<?php

declare(strict_types=1);

namespace Preflow\Roles\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Auth\Tests\Fixtures\TestUser;
use Preflow\Roles\ConfigRoleResolver;

final class ConfigRoleResolverTest extends TestCase
{
    private string $log;

    protected function setUp(): void
    {
        $this->log = tempnam(sys_get_temp_dir(), 'roles_log');
        ini_set('error_log', $this->log);
    }

    protected function tearDown(): void
    {
        ini_restore('error_log');
        @unlink($this->log);
    }

    private function resolver(bool $debug = false): ConfigRoleResolver
    {
        return new ConfigRoleResolver([
            'staff' => ['admin' => ['*'], 'editor' => ['page.*', 'news.*', 'assets.*']],
            'members' => ['premium' => ['downloads.premium']],
        ], $debug);
    }

    private function user(string ...$roles): TestUser
    {
        return new TestUser(roles: $roles);
    }

    public function test_editor_gets_the_patterns_of_its_role(): void
    {
        $r = $this->resolver();
        $this->assertTrue($r->allows($this->user('editor'), 'staff', 'page.edit', null));
        $this->assertTrue($r->allows($this->user('editor'), 'staff', 'assets.upload', null));
        $this->assertFalse($r->allows($this->user('editor'), 'staff', 'page', null));
        $this->assertFalse($r->allows($this->user('editor'), 'staff', 'site.edit', null));
    }

    public function test_admin_wildcard_allows_everything(): void
    {
        $this->assertTrue($this->resolver()->allows($this->user('admin'), 'staff', 'site.publish', null));
        $this->assertTrue($this->resolver()->allows($this->user('admin'), 'staff', 'folio.dashboard', null));
    }

    public function test_roles_are_looked_up_in_the_users_population_only(): void
    {
        $r = $this->resolver();
        $this->assertTrue($r->allows($this->user('premium'), 'members', 'downloads.premium', null));
        // A staff role name held by a member grants nothing in the members population.
        $this->assertFalse($r->allows($this->user('admin'), 'members', 'downloads.premium', null));
        $this->assertFalse($r->allows($this->user('premium'), 'staff', 'downloads.premium', null));
    }

    public function test_any_of_several_roles_may_grant(): void
    {
        $this->assertTrue($this->resolver()->allows($this->user('nobody', 'editor'), 'staff', 'news.edit', null));
    }

    public function test_an_unknown_role_grants_nothing_and_logs_once_in_debug(): void
    {
        $r = $this->resolver(debug: true);
        $this->assertFalse($r->allows($this->user('ghost'), 'staff', 'page.edit', null));
        $this->assertFalse($r->allows($this->user('ghost'), 'staff', 'page.view', null));
        $this->assertSame(1, substr_count((string) file_get_contents($this->log), 'ghost'));
    }

    public function test_an_unknown_role_is_silent_in_production(): void
    {
        $this->assertFalse($this->resolver()->allows($this->user('ghost'), 'staff', 'page.edit', null));
        $this->assertSame('', (string) file_get_contents($this->log));
    }

    public function test_a_user_without_roles_gets_nothing(): void
    {
        $this->assertFalse($this->resolver()->allows($this->user(), 'staff', 'page.view', null));
    }

    public function test_malformed_config_entries_grant_nothing(): void
    {
        $r = new ConfigRoleResolver(['staff' => ['editor' => 'page.*', 'odd' => [42, null]]]);
        $this->assertFalse($r->allows($this->user('editor'), 'staff', 'page.edit', null));
        $this->assertFalse($r->allows($this->user('odd'), 'staff', 'page.edit', null));
    }

    public function test_non_scalar_roles_are_skipped(): void
    {
        $user = new class implements \Preflow\Auth\Authenticatable {
            use \Preflow\Auth\AuthenticatableTrait;
            public string $uuid = 'u';
            public string $passwordHash = '';
            public function getRoles(): array { return [['nested'], null, 'editor']; }
        };

        $this->assertTrue($this->resolver()->allows($user, 'staff', 'page.edit', null));
    }
}
