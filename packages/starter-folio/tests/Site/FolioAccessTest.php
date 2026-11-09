<?php

declare(strict_types=1);

namespace Tests\Site;

use App\Console\StaffCreateCommand;
use App\Models\Staff;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\NativePasswordHasher;
use Preflow\Data\DataManager;
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
        $this->bootStarter();
        // Until the demo seed creates staff (Task 12), the test makes its own accounts.
        $this->createStaff('admin@example.com', 'demo-admin', 'admin');
        $this->createStaff('editor@example.com', 'demo-editor', 'editor');
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
        // Signed in: the employer area opens (its job list needs Task 12's publishing, the form does not).
        $this->get('/employer/jobs/new', ['Cookie' => $this->cookieHeader($cookies)])->assertOk();

        $this->get('/folio', ['Cookie' => $this->cookieHeader($cookies)])->assertRedirect('/folio/login');
    }

    public function test_staff_session_does_not_open_employer_area(): void
    {
        $cookies = $this->signInStaff('admin@example.com', 'demo-admin');
        $this->get('/folio', ['Cookie' => $this->cookieHeader($cookies)])->assertOk();

        $this->get('/employer', ['Cookie' => $this->cookieHeader($cookies)])->assertRedirect('/employer/login?next=%2Femployer');
    }

    public function test_staff_create_command(): void
    {
        [$code, $output] = $this->runStaffCreate(['new@example.com', '--role=editor']);

        $this->assertSame(0, $code, $output);
        $this->assertSame(1, preg_match('/^Created new@example\.com \(editor\)\. Password \(shown once\): ([0-9a-f]{18})$/m', $output, $match), $output);
        $this->assertSame(1, substr_count($output, $match[1]));
        $staff = $this->container()->get(DataManager::class)->query(Staff::class)->where('email', 'new@example.com')->first();
        $this->assertSame(['editor'], $staff->roles);
        $this->assertTrue(password_verify($match[1], $staff->passwordHash));

        // The new account signs in to Folio with the printed password.
        $cookies = $this->signInStaff('new@example.com', $match[1]);
        $this->get('/folio/job', ['Cookie' => $this->cookieHeader($cookies)])->assertOk();

        [$code, $output] = $this->runStaffCreate(['other@example.com', '--role=owner']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Role must be admin or editor.', $output);

        [$code, $output] = $this->runStaffCreate(['NEW@example.com', '--role=admin']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('already exists', $output);
        $this->assertStringNotContainsString('Password', $output);
    }

    private function createStaff(string $email, string $password, string $role): void
    {
        $staff = new Staff();
        $staff->uuid = md5('starter-staff-' . $email);
        $staff->email = $email;
        $staff->passwordHash = (new NativePasswordHasher())->hash($password);
        $staff->roles = [$role];
        $this->container()->get(DataManager::class)->save($staff);
    }

    /** @return array{int, string} exit code and output */
    private function runStaffCreate(array $args): array
    {
        $previous = getcwd();
        chdir($this->starterRoot);
        ob_start();
        try {
            $code = (new StaffCreateCommand())->execute($args);
        } finally {
            $output = (string) ob_get_clean();
            chdir($previous ?: '/');
        }

        return [$code, $output];
    }
}
