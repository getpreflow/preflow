<?php

declare(strict_types=1);

namespace Preflow\Rbac\Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Rbac\PermissionCoverage;
use Preflow\Rbac\Types\RoleRules;

/**
 * Nobody grants permissions they don't hold: a staff editor saves or deletes only roles
 * whose patterns their own roles cover, and adds only covered patterns. Installed: staff
 * "admin" ['*']; the config user editor@ has the staff role "editor" (roles and pages),
 * the role "helper" is below it.
 */
final class DelegationTest extends TestCase
{
    use RbacAppScaffold;

    private const array EDITOR = ['folio.dashboard', 'rbac_role.*', 'page.*'];

    protected function setUp(): void
    {
        $this->scaffold();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    private function editorSetup(): string
    {
        $this->install();
        $this->role('r-editor', 'staff', 'editor', self::EDITOR);
        $this->role('r-helper', 'staff', 'helper', ['page.view']);
        foreach ($this->roles() as $role) {
            if ($role->get('name') === 'admin') {
                return (string) $role->getId();
            }
        }
        $this->fail('no admin role');
    }

    /** @param list<string> $permissions */
    private function save(string $uri, string $name, array $permissions, string $population = 'staff'): \Psr\Http\Message\ResponseInterface
    {
        return $this->send('POST', $uri, ['name' => $name, 'label' => ucfirst($name), 'population' => $population, 'permissions' => ['', ...$permissions]]);
    }

    private static function text(\Psr\Http\Message\ResponseInterface $res): string
    {
        return html_entity_decode((string) $res->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    #[RunInSeparateProcess]
    public function test_held_is_the_signed_in_staff_users_patterns(): void
    {
        $this->editorSetup();
        $coverage = $this->container()->get(PermissionCoverage::class);
        $this->assertNull($coverage->held(), 'nobody signed in: delegation does not apply');

        $this->as('editor');
        $this->send('GET', '/folio/rbac_role');
        $this->assertSame(self::EDITOR, $coverage->held());
    }

    #[RunInSeparateProcess]
    public function test_new_role_with_everything_refused(): void
    {
        $this->editorSetup();
        $this->as('editor');

        $res = $this->save('/folio/rbac_role', 'boss', ['page.view', '*']);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString("You can't grant permissions you don't have: *", self::text($res));
        $this->assertCount(3, $this->roles());
    }

    #[RunInSeparateProcess]
    public function test_message_in_english_on_a_german_site(): void
    {
        // The app translator speaks German (content locale), the Folio admin stays English;
        // the site's lang/en/rbac.php still changes single keys.
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Rbac', 'debug' => 1, 'engine' => 'twig', 'key' => 'rbac-app-test-key', 'locale' => 'de'];\n");
        $this->write('lang/de/rbac.php', "<?php\nreturn ['delegation' => ['grant' => 'Nein: :patterns']];\n");
        $this->write('lang/en/rbac.php', "<?php\nreturn ['delegation' => ['last' => 'Keep one admin.']];\n");
        $this->editorSetup();
        $translator = $this->container()->get(\Preflow\I18n\Translator::class);
        $this->assertSame('de', $translator->getLocale());
        $this->as('editor');

        $res = $this->save('/folio/rbac_role', 'boss', ['*']);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString("You can't grant permissions you don't have: *", self::text($res));
        $this->assertSame('Keep one admin.', $this->container()->get(\Preflow\Rbac\Messages::class)->get('delegation.last'));
    }

    #[RunInSeparateProcess]
    public function test_adding_an_uncovered_pattern_refused(): void
    {
        $this->editorSetup();
        $this->as('editor');

        $res = $this->save('/folio/rbac_role/r-helper', 'helper', ['page.view', 'folio.extensions', 'rbac_role.edit', 'member.*']);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString("You can't grant permissions you don't have: folio.extensions, member.*", self::text($res));
        $this->assertSame(['page.view'], $this->stored('rbac_role', 'r-helper')?->get('permissions'));
    }

    #[RunInSeparateProcess]
    public function test_covered_edits_saved(): void
    {
        $this->editorSetup();
        $this->as('editor');

        $this->assertSame(302, $this->save('/folio/rbac_role/r-helper', 'helper', ['page.*', 'rbac_role.view', 'folio.dashboard'])->getStatusCode());
        $this->assertSame(['page.*', 'rbac_role.view', 'folio.dashboard'], $this->stored('rbac_role', 'r-helper')?->get('permissions'));

        // A members role is governed by the same coverage.
        $this->assertSame(302, $this->save('/folio/rbac_role', 'reader', ['page.view'], 'members')->getStatusCode());
        $this->assertSame(422, $this->save('/folio/rbac_role', 'insider', ['member.view'], 'members')->getStatusCode());

        $this->assertSame(302, $this->send('POST', '/folio/rbac_role/r-helper/delete')->getStatusCode());
        $this->assertNull($this->stored('rbac_role', 'r-helper'));
    }

    #[RunInSeparateProcess]
    public function test_editing_a_role_above_you_refused(): void
    {
        $admin = $this->editorSetup();
        $this->as('editor');

        // Only the label changes, but the editor doesn't hold "*".
        $res = $this->send('POST', '/folio/rbac_role/' . $admin, ['name' => 'admin', 'label' => 'Boss', 'population' => 'staff', 'permissions' => ['', '*']]);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString("You can't grant permissions you don't have: *", self::text($res));
        $this->assertSame('Administrator', $this->stored('rbac_role', $admin)?->get('label'));

        // Removing what the editor doesn't hold is touching it too.
        $this->assertSame(422, $this->send('POST', '/folio/rbac_role/' . $admin, ['name' => 'admin', 'label' => 'Administrator', 'population' => 'staff', 'permissions' => ['', 'page.view']])->getStatusCode());
        $this->assertSame(['*'], $this->stored('rbac_role', $admin)?->get('permissions'));
    }

    #[RunInSeparateProcess]
    public function test_deleting_a_role_above_you_refused(): void
    {
        $admin = $this->editorSetup();
        $this->as('editor');

        $this->assertSame(302, $this->send('POST', '/folio/rbac_role/' . $admin . '/delete')->getStatusCode());
        $this->assertNotNull($this->stored('rbac_role', $admin));
        $this->assertStringContainsString("You can't grant permissions you don't have: *", self::text($this->send('GET', '/folio/rbac_role')));
    }

    #[RunInSeparateProcess]
    public function test_without_a_signed_in_user_delegation_does_not_apply(): void
    {
        $this->editorSetup();
        $c = $this->container(); // as on the CLI (install, import)
        $rules = $c->get(RoleRules::class);
        $dm = $c->get(DataManager::class);
        $helper = $dm->findType('rbac_role', 'r-helper');

        $helper->set('permissions', ['*']);
        $rules->beforeSave($helper);
        $rules->beforeDelete($helper);
        $this->addToAssertionCount(1);
    }

    #[RunInSeparateProcess]
    public function test_matrix_disables_what_the_editor_cannot_grant(): void
    {
        $this->editorSetup();
        $this->as('editor');

        $form = (string) $this->send('GET', '/folio/rbac_role/r-helper/edit')->getBody();
        $denied = static fn (string $value): string => '#<input type="checkbox"[^>]* value="' . preg_quote($value, '#') . '"[^>]* disabled data-rbac-denied title="You can&apos;t grant this"#';
        $this->assertMatchesRegularExpression($denied('*'), $form);
        $this->assertMatchesRegularExpression($denied('member.view'), $form);
        $this->assertMatchesRegularExpression($denied('member.*'), $form);
        $this->assertMatchesRegularExpression($denied('folio.extensions'), $form);
        $this->assertMatchesRegularExpression($denied('folio.cache'), $form);
        foreach (['page.view', 'page.*', 'rbac_role.edit', 'folio.dashboard'] as $covered) {
            $this->assertDoesNotMatchRegularExpression($denied($covered), $form);
            $this->assertMatchesRegularExpression('#<input type="checkbox"[^>]* value="' . preg_quote($covered, '#') . '"#', $form);
        }
    }

    #[RunInSeparateProcess]
    public function test_matrix_for_everything_disables_nothing(): void
    {
        $this->editorSetup();
        $this->as('admin');

        $form = (string) $this->send('GET', '/folio/rbac_role/r-helper/edit')->getBody();
        $this->assertStringContainsString('value="*"', $form);
        $this->assertStringNotContainsString('data-rbac-denied', $form);
    }
}
