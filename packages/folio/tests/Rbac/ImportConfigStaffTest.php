<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Rbac;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\UserProviderInterface;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Rbac\Console\ImportConfigStaffAliasCommand;
use Preflow\Rbac\Console\ImportConfigStaffCommand;

/**
 * staff:import-config (until 0.70 also folio:staff:import-config): the config users of the staff guard (with Folio its
 * staff guard: folio.guard, else the default guard) become staff records with their password hash as is, roles mapped
 * by name to existing staff roles.
 */
final class ImportConfigStaffTest extends TestCase
{
    use RbacAppScaffold;

    private string $hash = '';

    protected function setUp(): void
    {
        $this->scaffold();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    /** @param list<array<string, mixed>>|null $users config users (null: admin, editor, keeper) */
    private function site(bool $staff = true, ?array $users = null, bool $chain = true, bool $staffGuard = false): void
    {
        if ($staff) {
            $this->write('config/folio/extensions.json', '{"enabled": {"rbac": {"disabled_parts": [], "enabled_parts": ["model:staff"]}}}');
        }
        $this->hash = password_hash('secret', PASSWORD_DEFAULT);
        $users ??= [
            ['email' => 'admin@site.test', 'password_hash' => $this->hash, 'roles' => ['admin']],
            ['email' => 'Editor@Site.test', 'password_hash' => $this->hash, 'roles' => ['editor', 'keeper']],
            ['email' => 'plain@site.test', 'password_hash' => $this->hash, 'roles' => []],
        ];
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => $chain ? 'chain' : 'config', 'population' => 'staff']],
            // Without the staff part no provider signs staff in with records (rbac would define the type for one).
            'providers' => ($staff ? ['staff' => ['class' => \Preflow\Auth\Record\RecordUserProvider::class, 'type' => 'staff', 'active' => 'active']] : []) + [
                'config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => $users],
                'chain' => ['class' => \Preflow\Auth\ChainUserProvider::class, 'providers' => $staff ? ['staff', 'config'] : ['config']],
            ],
            'session' => ['cookie' => 'rbac_import_test', 'path' => '/', 'secure' => false],
        ], true) . ";\n");
        if ($staffGuard) {
            $this->write('config/folio.php', "<?php\nreturn ['path' => '/folio', 'guard' => 'staff'];\n");
            $auth = require $this->dir . '/config/auth.php';
            $auth['default_guard'] = 'employers';
            $auth['guards'] = [
                'employers' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'employers', 'population' => 'employers'],
                'staff' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => $chain ? 'chain' : 'config', 'population' => 'staff'],
            ];
            $auth['providers']['employers'] = ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                ['email' => 'boss@company.test', 'password_hash' => $this->hash, 'roles' => ['admin']],
            ]];
            $this->write('config/auth.php', "<?php\nreturn " . var_export($auth, true) . ";\n");
        }
        $this->install();
        $this->role('r-editor', 'staff', 'editor', ['folio.dashboard', 'page.*']);
    }

    /**
     * The employers' guard is the default one (with config users of its own); Folio signs staff in
     * through the "staff" guard (config/folio.php 'guard'), a chain of staff records and config users.
     */
    private function siteWithStaffGuard(): void
    {
        $this->site(staffGuard: true);
    }

    #[RunInSeparateProcess]
    public function test_the_import_reads_folio_s_staff_guard_not_the_default_one(): void
    {
        $this->siteWithStaffGuard();

        [$code, $out] = $this->import();

        $this->assertSame(0, $code, $out);
        $this->assertNotNull($this->staffByEmail('admin@site.test'));
        $this->assertNull($this->staffByEmail('boss@company.test'), 'an employer never becomes staff');
        $this->assertSame(3, $this->staffCount());
    }

    #[RunInSeparateProcess]
    public function test_the_config_email_check_reads_folio_s_staff_guard(): void
    {
        $this->siteWithStaffGuard();
        $this->as('admin');

        $taken = $this->send('POST', '/folio/staff', ['email' => 'editor@site.test', 'name' => 'E', 'roles' => [''], 'active' => '1']);
        $this->assertSame(422, $taken->getStatusCode(), 'a config user of the staff guard has that address');
        $this->assertNull($this->staffByEmail('editor@site.test'));

        $free = $this->send('POST', '/folio/staff', ['email' => 'boss@company.test', 'name' => 'B', 'roles' => [''], 'active' => '1']);
        $this->assertSame(302, $free->getStatusCode(), 'the employers\' config users are no staff');
    }

    /** @return array{0: int, 1: string} */
    private function import(bool $oldName = false): array
    {
        $factory = fn () => $this->session ??= $this->app();
        $command = $oldName ? new ImportConfigStaffAliasCommand($factory) : new ImportConfigStaffCommand($factory);
        $this->assertSame($oldName ? 'folio:staff:import-config' : 'staff:import-config', $command->getName());
        ob_start();
        try {
            $code = $command->execute([]);
        } finally {
            $out = (string) ob_get_clean();
        }

        return [$code, $out];
    }

    private function staffByEmail(string $email): ?DynamicRecord
    {
        $record = $this->container()->get(DataManager::class)->queryType('staff')->where('email', $email)->first();

        return $record instanceof DynamicRecord ? $record : null;
    }

    private function staffCount(): int
    {
        return count($this->container()->get(DataManager::class)->queryType('staff')->get()->items());
    }

    #[RunInSeparateProcess]
    public function test_it_creates_a_record_per_config_user_with_the_same_hash(): void
    {
        $this->site();

        [$code, $out] = $this->import();

        $this->assertSame(0, $code, $out);
        $admin = $this->staffByEmail('admin@site.test');
        $this->assertNotNull($admin);
        $this->assertSame($this->hash, $admin->get('password_hash'));
        $this->assertSame('admin@site.test', $admin->get('name'));
        $this->assertTrue($admin->get('active'));
        $this->assertSame('0', $admin->get('auth_epoch'));
        $this->assertNotFalse(\DateTimeImmutable::createFromFormat(DATE_ATOM, (string) $admin->get('created_at')));
        $this->assertSame(3, $this->staffCount());
        $this->assertStringContainsString('created admin@site.test', $out);
        $this->assertStringContainsString('created editor@site.test', $out, 'e-mails are lowercased');
        $this->assertStringNotContainsString($this->hash, $out);
    }

    #[RunInSeparateProcess]
    public function test_the_old_password_logs_in_through_the_chain_record_branch(): void
    {
        $this->site();
        $this->import();

        $user = $this->container()->get(UserProviderInterface::class)->findByCredentials(['email' => 'admin@site.test']);

        $this->assertInstanceOf(\Preflow\Auth\Record\RecordUser::class, $user, 'the record decides now');
        $this->assertTrue(password_verify('secret', $user->getPasswordHash()));
        $this->assertSame(['admin'], $user->getRoles());
    }

    #[RunInSeparateProcess]
    public function test_the_old_password_signs_in_to_folio_after_the_import(): void
    {
        $this->site();
        $this->import();
        $f = new Psr17Factory();
        $page = (string) $this->session->handle($f->createServerRequest('GET', '/folio/login'))->getBody();
        preg_match('/name="_csrf_token" value="([^"]+)"/', $page, $m);

        $res = $this->session->handle($f->createServerRequest('POST', '/folio/login')->withHeader('Sec-Fetch-Site', 'same-origin')->withParsedBody([
            '_csrf_token' => $m[1] ?? '', 'email' => 'admin@site.test', 'password' => 'secret',
        ]));

        $this->assertSame('/folio', $res->getHeaderLine('Location'));
        $this->assertSame(200, $this->session->handle($f->createServerRequest('GET', '/folio'))->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_roles_are_mapped_by_name_and_an_unknown_one_is_skipped_with_a_warning(): void
    {
        $this->site();

        [$code, $out] = $this->import();

        $this->assertSame(0, $code, $out);
        $this->assertSame(['admin'], \Preflow\Rbac\RoleList::normalize($this->staffByEmail('admin@site.test')->get('roles')));
        $this->assertSame(['editor'], \Preflow\Rbac\RoleList::normalize($this->staffByEmail('editor@site.test')->get('roles')));
        $this->assertSame([], \Preflow\Rbac\RoleList::normalize($this->staffByEmail('plain@site.test')->get('roles')));
        $this->assertStringContainsString('warning: editor@site.test: unknown role "keeper" skipped', $out);
    }

    #[RunInSeparateProcess]
    public function test_a_second_run_creates_nothing_and_reports_the_skips(): void
    {
        $this->site();
        $this->import();
        $record = $this->staffByEmail('admin@site.test');
        $record->set('name', 'Renamed');
        $this->container()->get(DataManager::class)->saveType($record, false);

        [$code, $out] = $this->import();

        $this->assertSame(0, $code, $out);
        $this->assertSame(3, $this->staffCount());
        $this->assertSame('Renamed', $this->staffByEmail('admin@site.test')->get('name'), 'an existing account is left alone');
        $this->assertStringContainsString('skipped admin@site.test (already a staff account)', $out);
        $this->assertStringNotContainsString('created a', $out); $this->assertStringContainsString('created 0, skipped 3', $out);
    }

    #[RunInSeparateProcess]
    public function test_it_reads_a_config_provider_used_directly_as_the_guard_provider(): void
    {
        $this->site(chain: false);

        [$code, $out] = $this->import();

        $this->assertSame(0, $code, $out);
        $this->assertSame(3, $this->staffCount());
    }

    #[RunInSeparateProcess]
    public function test_without_the_staff_type_it_stops_with_a_clear_error(): void
    {
        $this->site(staff: false);

        [$code, $out] = $this->import();

        $this->assertSame(1, $code);
        $this->assertStringContainsString("This site has no staff accounts: config/auth.php names no user provider of class Preflow\\Auth\\Record\\RecordUserProvider with 'type' => 'staff'.", $out);
        $this->assertStringNotContainsString('folio', strtolower($out));
    }

    #[RunInSeparateProcess]
    public function test_the_old_name_still_imports_and_says_it_is_deprecated(): void
    {
        $this->site();

        [$code, $out] = $this->import(oldName: true);

        $this->assertSame(0, $code, $out);
        $this->assertStringStartsWith("Note: \"folio:staff:import-config\" is deprecated; use \"staff:import-config\".\n", $out);
        $this->assertSame(3, $this->staffCount());
        $this->assertStringContainsString('staff:import-config — created 3, skipped 0', $out);
    }

    #[RunInSeparateProcess]
    public function test_it_can_run_before_any_staff_role_besides_admin_exists(): void
    {
        $this->site(users: [['email' => 'solo@site.test', 'password_hash' => 'x', 'roles' => ['admin']]]);

        [$code, $out] = $this->import();

        $this->assertSame(0, $code, $out);
        $this->assertSame(['admin'], \Preflow\Rbac\RoleList::normalize($this->staffByEmail('solo@site.test')->get('roles')));
    }
}
