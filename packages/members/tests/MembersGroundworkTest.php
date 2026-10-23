<?php

declare(strict_types=1);

namespace Preflow\Members\Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Access\Access;
use Preflow\Auth\ArrayUser;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Console\ExportCommand;
use Preflow\Folio\Console\ExtensionsCheckCommand;
use Preflow\Folio\Extension\ExtensionState;
use Preflow\Folio\Transfer\TarReader;
use Preflow\Members\Extension;
use Preflow\Rbac\RbacResolver;
use Preflow\Rbac\RoleList;
use Preflow\Rbac\RoleStore;
use Preflow\Members\Tests\Support\Dom;
use Preflow\Members\Tests\Support\MembersScaffold;

/** The member type on SQLite (or the default storage), its roles and settings, the sidebar, no export. */
final class MembersGroundworkTest extends TestCase
{
    use MembersScaffold;

    protected function setUp(): void
    {
        $this->scaffold();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    public function test_extensions_check_ok(): void
    {
        [$code, $out] = $this->command(new ExtensionsCheckCommand($this->dir));

        $this->assertSame(0, $code, $out);
        $this->assertSame("ok\n", $out);
    }

    public function test_migrate_and_apply_install_members_and_its_roles(): void
    {
        $out = $this->migrateAndApply();

        $this->assertStringContainsString('created table member', $out);
        $this->assertStringContainsString('installed members 1.2.0', $out);
        $roles = $this->memberRoles();
        $this->assertSame(['member', 'pending', 'rejected'], array_keys($roles));
        $this->assertSame(['members.access'], RoleList::normalize($roles['member']->get('permissions')));
        $this->assertSame([], RoleList::normalize($roles['pending']->get('permissions')));
        $this->assertSame('Waiting for approval', $roles['pending']->get('label'));
    }

    public function test_install_is_idempotent_and_keeps_existing_roles(): void
    {
        $this->migrateAndApply();
        // As on a live site: the owner edited the member role in Folio.
        $member = $this->memberRoles()['member'];
        $member->set('label', 'Reader');
        $member->set('permissions', ['members.access', 'members.decide']);
        $this->container()->get(DataManager::class)->saveType($member, false);
        $this->flow = null;

        $c = $this->container();
        $count = count($c->get(DataManager::class)->queryType('rbac_role')->get()->items());
        $c->get(Extension::class)->install($c);
        $c->get(Extension::class)->install($c);

        $roles = $this->memberRoles();
        $this->assertSame(['member', 'pending', 'rejected'], array_keys($roles));
        $this->assertCount($count, $c->get(DataManager::class)->queryType('rbac_role')->get()->items(), 'no role added');
        $this->assertSame('Reader', $roles['member']->get('label'));
        $this->assertSame(['members.access', 'members.decide'], RoleList::normalize($roles['member']->get('permissions')));
    }

    public function test_install_over_roles_of_an_earlier_version_adds_only_whats_missing(): void
    {
        // smart-stack's own extension (members 1.1.0) created the roles already; one is gone.
        $this->migrateAndApply();
        $c = $this->container();
        $state = $c->get(ExtensionState::class);
        $state->markInstalled('members', '1.1.0');
        $c->get(DataManager::class)->deleteType('rbac_role', (string) $this->memberRoles()['rejected']->getId());
        $kept = (string) $this->memberRoles()['member']->getId();
        $this->flow = null;

        [$code, $out] = $this->command(new \Preflow\Folio\Console\ExtensionsApplyCommand(fn () => $this->app()));

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('installed members 1.2.0 (was 1.1.0)', $out);
        $roles = $this->memberRoles();
        $this->assertSame(['member', 'pending', 'rejected'], array_keys($roles));
        $this->assertSame($kept, (string) $roles['member']->getId());
    }

    public function test_member_roles_roundtrip_on_sqlite(): void
    {
        $this->migrateAndApply();
        $this->saveRecord('member', ['uuid' => 'm-1', 'email' => 'reader@site.test', 'name' => 'Reader', 'roles' => ['member']]);

        $c = $this->container();
        $member = $c->get(DataManager::class)->findType('member', 'm-1');
        $this->assertNotNull($member);
        $stored = (new \PDO('sqlite:' . $this->dir . '/storage/site.sqlite'))
            ->query("SELECT roles FROM member WHERE uuid = 'm-1'")->fetchColumn();
        $this->assertSame(['member'], json_decode((string) $stored, true), 'stored on SQLite (alias members)');

        $this->assertTrue($c->get(Access::class)->registry()->isDeclared('members.access'));
        $this->assertTrue($c->get(Access::class)->registry()->isDeclared('members.decide'));
        $user = new ArrayUser('m-1', 'reader@site.test', '', (array) $member->get('roles'));
        $resolver = new RbacResolver($c->get(RoleStore::class), $c->get(ExtensionState::class));
        $this->assertTrue($resolver->allows($user, 'members', 'members.access', null));
        $this->assertFalse($resolver->allows(new ArrayUser('m-2', 'waiting@site.test', '', ['pending']), 'members', 'members.access', null));
    }

    public function test_member_type_shape(): void
    {
        $def = $this->container()->get(DataManager::class)->getTypeRegistry()->get('member');

        $this->assertSame('member', $def->table);
        $this->assertSame('members', $def->storage);
        $this->assertSame('uuid', $def->idField);
        $this->assertSame(['email', 'password_hash', 'name', 'language', 'registered_at', 'email_confirmed_at', 'decided_at', 'roles', 'auth_epoch'], array_keys($def->fields));
        $this->assertSame(['en' => 'English', 'de' => 'Deutsch'], $def->fields['language']->config['options']);
    }

    public function test_storage_is_default_without_the_members_alias(): void
    {
        $this->removeScaffold();
        $this->scaffold(aliases: ['auth' => 'sqlite']);
        $this->migrateAndApply();

        $this->assertSame('default', $this->container()->get(DataManager::class)->getTypeRegistry()->get('member')->storage);
        $this->saveRecord('member', ['uuid' => 'm-1', 'email' => 'reader@site.test', 'name' => 'Reader', 'roles' => ['member']]);
        $this->assertFileExists($this->dir . '/storage/data/member/m-1.json', 'on the default (JSON) storage');
    }

    public function test_language_options_follow_the_sites_locales(): void
    {
        $this->removeScaffold();
        $this->scaffold(locales: ['de', 'fr', 'en']);

        $options = $this->container()->get(DataManager::class)->getTypeRegistry()->get('member')->fields['language']->config['options'];
        $this->assertSame(['de' => 'Deutsch', 'fr' => 'Français', 'en' => 'English'], $options);
    }

    public function test_boot_fails_without_the_rbac_extension(): void
    {
        $this->write('config/folio/extensions.json', '{"enabled": {"members": {"disabled_parts": []}}}');

        $this->expectExceptionMessage('The members extension needs the rbac extension');
        $this->app();
    }

    #[RunInSeparateProcess]
    public function test_sidebar_and_settings(): void
    {
        $this->migrateAndApply();
        $this->signIn();
        $nav = $this->sidebar($this->admin('/folio'));

        $this->assertSame(['Members'], $nav['Members']);
        $this->assertContains('Members settings', $nav['Settings']);
        $settings = $this->admin('/folio/members_settings');
        foreach (['Registration open', 'Notify about waiting members', 'Accept message', 'Decline message'] as $label) {
            $this->assertStringContainsString($label, $settings);
        }
        foreach (['Book code skips screening', 'Screening', 'Welcome note', 'SMART STACK', 'Steffen'] as $gone) {
            $this->assertStringNotContainsString($gone, $settings, $gone);
        }
    }

    #[RunInSeparateProcess]
    public function test_settings_show_the_standard_texts_as_placeholders_in_the_edited_locale(): void
    {
        $this->write('lang/de/members.php', "<?php\nreturn ['mail' => ['declined' => ['body' => 'Hallo {name}, leider nein.']]];\n");
        $this->migrateAndApply();
        $this->signIn();
        $en = require dirname(__DIR__) . '/lang/en/members.php';
        $de = require dirname(__DIR__) . '/lang/de/members.php';

        foreach (['' => [$en['mail']['accepted']['body'], $en['mail']['declined']['body']], '?locale=de' => [$de['mail']['accepted']['body'], 'Hallo {name}, leider nein.']] as $query => [$accept, $decline]) {
            $html = $this->admin('/folio/members_settings' . $query);
            $this->assertSame($accept, Dom::first($html, '//textarea[@name="accept_message"]')?->getAttribute('placeholder'), 'accept ' . $query);
            $this->assertSame($decline, Dom::first($html, '//textarea[@name="decline_message"]')?->getAttribute('placeholder'), 'decline ' . $query . ' (the site\'s own wording)');
            $this->assertSame('', trim((string) Dom::first($html, '//textarea[@name="accept_message"]')?->textContent), 'the field itself stays empty');
        }
    }

    #[RunInSeparateProcess]
    public function test_boots_with_the_settings_part_switched_off(): void
    {
        $this->write('config/folio/extensions.json', '{"enabled": {"members": {"disabled_parts": ["settings"]}, "rbac": {"disabled_parts": []}}}');
        $this->migrateAndApply();
        $this->signIn();

        $this->assertStringNotContainsString('Members settings', $this->admin('/folio'));
    }

    #[RunInSeparateProcess]
    public function test_member_list_hides_password_hash(): void
    {
        $this->migrateAndApply();
        $this->saveRecord('member', [
            'uuid' => 'm-1', 'email' => 'reader@site.test', 'name' => 'Reader', 'password_hash' => 'HASH-NEVER-SHOWN',
            'registered_at' => '2026-10-01 10:00', 'decided_at' => '', 'roles' => ['member'],
        ]);
        $this->signIn();
        $list = $this->admin('/folio/member');
        $form = $this->admin('/folio/member/m-1/edit');

        $headings = array_map(static fn (\DOMElement $th): string => trim($th->textContent), Dom::all($list, '//table//thead//th'));
        $this->assertSame(['Email', 'Name', 'Registered At'], array_slice($headings, 0, 3));
        $this->assertNotContains('Password Hash', $headings);
        $this->assertStringNotContainsString('HASH-NEVER-SHOWN', $list);

        $this->assertStringContainsString('name="email"', $form);
        $this->assertStringNotContainsString('name="password_hash"', $form);
        $this->assertStringNotContainsString('HASH-NEVER-SHOWN', $form);
        foreach (['registered_at', 'decided_at'] as $field) {
            $this->assertStringNotContainsString('name="' . $field . '"', $form, $field);
        }
        $this->assertStringContainsString('2026-10-01 10:00', $form); // read-only text
    }

    #[RunInSeparateProcess]
    public function test_member_roles_and_language_are_readonly(): void
    {
        $this->migrateAndApply();
        $this->saveRecord('member', ['uuid' => 'm-1', 'email' => 'ada@example.com', 'name' => 'Ada', 'language' => 'de', 'roles' => ['member']]);
        $this->signIn();

        $form = $this->admin('/folio/member/m-1/edit');

        $this->assertStringContainsString('name="email"', $form);
        foreach (['roles', 'language', 'email_confirmed_at'] as $field) {
            $this->assertStringNotContainsString('name="' . $field, $form, $field);
        }
        $this->assertStringContainsString('Deutsch', $form, 'shown as read-only text');
    }

    public function test_members_guard_finds_member_records(): void
    {
        $this->migrateAndApply();
        $this->saveRecord('member', ['uuid' => 'm-1', 'email' => 'ada@example.com', 'name' => 'Ada', 'roles' => ['member']]);

        $auth = $this->container()->get(\Preflow\Auth\AuthManager::class);

        $this->assertInstanceOf(\Preflow\Auth\SessionGuard::class, $auth->guard('members'));
        $provider = (new \ReflectionProperty(\Preflow\Auth\SessionGuard::class, 'provider'))->getValue($auth->guard('members'));
        $this->assertInstanceOf(\Preflow\Folio\Users\RecordUser::class, $provider->findByCredentials(['email' => 'ada@example.com']));
        $this->assertNull($provider->findByCredentials(['email' => 'nobody@example.com']));
    }

    public function test_member_not_exported(): void
    {
        $this->migrateAndApply();
        $file = $this->dir . '/export.tar';
        $stderr = fopen('php://memory', 'w+b');

        $code = (new ExportCommand(fn () => $this->app(), null, $stderr))->execute([$file, '--only=data']);

        rewind($stderr);
        $this->assertSame(0, $code, (string) stream_get_contents($stderr));
        $tables = $this->manifest($file)['tables'];
        $this->assertArrayHasKey('page', $tables);
        $this->assertArrayNotHasKey('member', $tables);
    }

    /** @return array<string, DynamicRecord> the members population's roles by name, sorted */
    private function memberRoles(): array
    {
        $roles = [];
        foreach ($this->container()->get(DataManager::class)->queryType('rbac_role')->get()->items() as $role) {
            if ($role->get('population') === 'members') {
                $roles[(string) $role->get('name')] = $role;
            }
        }
        ksort($roles);

        return $roles;
    }

    /** @return array<string, list<string>> section label => item labels, in sidebar order (the dashboard has no label) */
    private function sidebar(string $html): array
    {
        $nav = [];
        $section = 'Dashboard';
        foreach (Dom::all($html, '//nav[contains(@class, "folio-nav")]/*') as $node) {
            if ($node->tagName === 'span') {
                $section = trim($node->textContent);
            } elseif ($node->tagName === 'a') {
                $nav[$section][] = trim($node->textContent);
            }
        }

        return $nav;
    }

    /** @return array<string, mixed> the export's folio-export.json */
    private function manifest(string $file): array
    {
        $stream = fopen($file, 'rb');
        foreach ((new TarReader($stream))->entries() as [$name, , $copyTo]) {
            $this->assertSame('folio-export.json', $name);
            $buf = fopen('php://temp', 'w+b');
            $copyTo($buf);
            rewind($buf);

            return json_decode((string) stream_get_contents($buf), true);
        }
        $this->fail('empty archive');
    }
}
