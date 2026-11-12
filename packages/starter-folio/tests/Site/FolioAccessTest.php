<?php

declare(strict_types=1);

namespace Tests\Site;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\DevTools\Console;
use Preflow\Rbac\RoleList;
use Preflow\Rbac\Staff\StaffAccounts;
use Tests\Support\StarterApp;

/**
 * Staff (guard "staff") and employers (guard "employers") are two populations: each signs in at
 * its own page, and a session of one never opens the other's area. Roles decide what staff may do.
 */
final class FolioAccessTest extends TestCase
{
    use StarterApp;

    protected function setUp(): void
    {
        // The demo seed creates the staff accounts admin@example.com and editor@example.com.
        $this->bootStarter();
    }

    protected function tearDown(): void
    {
        $this->removeStarter();
    }

    public function test_staff_admin_reaches_folio_job_list(): void
    {
        $cookies = $this->signInStaff('admin@example.com', 'demo-admin');

        $response = $this->get('/folio/job', ['Cookie' => $this->cookieHeader($cookies)]);

        $response->assertOk();
        $this->assertStringContainsString('Frontend Developer', $response->body(), 'the list shows the seeded jobs');
    }

    public function test_editor_opens_a_job_and_the_page_texts(): void
    {
        $cookies = ['Cookie' => $this->cookieHeader($this->signInStaff('editor@example.com', 'demo-editor'))];

        $this->get('/folio/job/' . md5('starter-job-2') . '/edit', $cookies)->assertOk()->assertSee('Frontend Developer');
        $this->get('/folio/jobs_page', $cookies)->assertOk()->assertSee('Open positions from small companies');
        $this->get('/folio/job_page', $cookies)->assertOk();
        $this->get('/folio/company_page', $cookies)->assertOk();
        $this->get('/folio/assets', $cookies)->assertOk();
    }

    public function test_job_form_hides_the_dates_the_app_sets(): void
    {
        $cookies = ['Cookie' => $this->cookieHeader($this->signInStaff('editor@example.com', 'demo-editor'))];

        $body = $this->get('/folio/job/' . md5('starter-job-2') . '/edit', $cookies)->assertOk()->body();

        $this->assertStringContainsString('name="slug"', $body);
        $this->assertStringNotContainsString('name="createdAt"', $body);
        $this->assertStringNotContainsString('name="updatedAt"', $body);
    }

    public function test_admin_opens_admin_only_screen(): void
    {
        $cookies = $this->signInStaff('admin@example.com', 'demo-admin');

        // The control for the editor's 403 below: the screen exists, and '*' opens it.
        $this->get('/folio/extensions', ['Cookie' => $this->cookieHeader($cookies)])->assertOk();
    }

    public function test_editor_cannot_open_admin_only_screen(): void
    {
        $cookies = $this->signInStaff('editor@example.com', 'demo-editor');

        $this->get('/folio/job', ['Cookie' => $this->cookieHeader($cookies)])->assertOk();
        // The extensions screen asks folio.extensions, which only the admin's '*' grants.
        $this->get('/folio/extensions', ['Cookie' => $this->cookieHeader($cookies)])->assertStatus(403);
    }

    public function test_employer_session_does_not_open_folio(): void
    {
        $cookies = $this->signInEmployer();
        // Signed in: the employer area opens.
        $this->get('/employer', ['Cookie' => $this->cookieHeader($cookies)])->assertOk();

        $this->get('/folio', ['Cookie' => $this->cookieHeader($cookies)])->assertRedirect('/folio/login');
    }

    public function test_staff_session_does_not_open_employer_area(): void
    {
        $cookies = $this->signInStaff('admin@example.com', 'demo-admin');
        $this->get('/folio', ['Cookie' => $this->cookieHeader($cookies)])->assertOk();

        $this->get('/employer', ['Cookie' => $this->cookieHeader($cookies)])->assertRedirect('/employer/login?next=%2Femployer');
    }

    /** Folio carries rbac's admin: an admin lists staff and roles. */
    public function test_admin_opens_the_staff_and_roles_screens(): void
    {
        $admin = ['Cookie' => $this->cookieHeader($this->signInStaff('admin@example.com', 'demo-admin'))];

        $this->assertStringContainsString('editor@example.com', $this->get('/folio/staff', $admin)->assertOk()->body());
        $this->assertStringContainsString('Editor', $this->get('/folio/rbac_role', $admin)->assertOk()->body());
    }

    /** An editor has no staff.* or rbac_role.* permissions. */
    public function test_editor_cannot_open_the_staff_and_roles_screens(): void
    {
        $editor = ['Cookie' => $this->cookieHeader($this->signInStaff('editor@example.com', 'demo-editor'))];

        $this->assertSame(403, $this->get('/folio/staff', $editor)->status());
        $this->assertSame(403, $this->get('/folio/rbac_role', $editor)->status());
    }

    /** staff:create (rbac's) makes an account without a password; once one is set, it signs in with its roles. */
    public function test_staff_create_command(): void
    {
        [$code, $output] = $this->runConsole('staff:create', 'new@example.com', '--name=Nina', '--role=editor');

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('Created staff account new@example.com (editor)', $output);
        $accounts = $this->container()->get(StaffAccounts::class);
        $staff = $accounts->find('new@example.com');
        $this->assertSame(['editor'], RoleList::normalize($staff->get('roles')));
        $this->assertSame('', (string) $staff->get('password_hash'), 'no password yet');

        // Without a password the account cannot sign in.
        $cookies = $this->signInStaff('new@example.com', 'a-long-password');
        $this->get('/folio/job', ['Cookie' => $this->cookieHeader($cookies)])->assertRedirect('/folio/login');

        $accounts->setPassword($staff, 'a-long-password');
        $cookies = $this->signInStaff('new@example.com', 'a-long-password');
        $this->get('/folio/job', ['Cookie' => $this->cookieHeader($cookies)])->assertOk();

        [$code, $output] = $this->runConsole('staff:create', 'other@example.com', '--role=owner');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('owner', $output);

        [$code, $output] = $this->runConsole('staff:create', 'NEW@example.com', '--role=admin');
        $this->assertSame(1, $code);
        $this->assertNull($accounts->find('other@example.com'));
    }

    /** Any staff role the app defines: one added to config/roles.php is seeded by rbac:install. */
    public function test_staff_create_accepts_every_defined_staff_role(): void
    {
        $roles = require $this->starterRoot . '/config/roles.php';
        $roles['staff']['seo'] = ['folio.dashboard', 'page.view', 'page.edit'];
        file_put_contents($this->starterRoot . '/config/roles.php', "<?php\nreturn " . var_export($roles, true) . ";\n");
        [$code, $output] = $this->runConsole('rbac:install');
        $this->assertSame(0, $code, $output);

        [$code, $output] = $this->runConsole('staff:create', 'seo@example.com', '--role=seo');
        $this->assertSame(0, $code, $output);
        $this->assertSame(['seo'], RoleList::normalize($this->container()->get(StaffAccounts::class)->find('seo@example.com')->get('roles')));
    }

    /** The revisions panel names staff by their name, not their id (AuthorNames asks the staff guard's provider). */
    public function test_revision_authors_are_shown_by_name(): void
    {
        $job = md5('starter-job-2');
        $editor = md5('starter-staff-2'); // the demo seed's editor@example.com, "Demo Editor"
        $record = $this->container()->get(DataManager::class)->findType('job', $job);
        $this->assertNotNull($record);
        $this->container()->get(\Preflow\Folio\Publishing\Publisher::class)->publish($record, $editor);

        $cookies = ['Cookie' => $this->cookieHeader($this->signInStaff('admin@example.com', 'demo-admin'))];
        $body = $this->get('/folio/job/' . $job . '/edit', $cookies)->assertOk()->body();
        preg_match_all('~<span class="folio-revision-by">([^<]*)</span>~', $body, $by);

        $this->assertContains('Demo Editor', $by[1], 'the editor\'s name');
        $this->assertNotContains($editor, $by[1], 'not the raw id');
    }

    /** @return array{int, string} exit code and output */
    private function runConsole(string ...$command): array
    {
        $previous = getcwd();
        chdir($this->starterRoot);
        ob_start();
        try {
            $code = (new Console($this->starterRoot))->run(['preflow', ...$command]);
        } finally {
            $output = (string) ob_get_clean();
            chdir($previous ?: '/');
        }

        return [$code, $output];
    }
}
