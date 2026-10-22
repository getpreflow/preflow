<?php

declare(strict_types=1);

namespace Preflow\Auth\Tests\Access;

use PHPUnit\Framework\TestCase;
use Preflow\Auth\Access\Access;
use Preflow\Auth\Access\PermissionRegistry;
use Preflow\Auth\Access\PermissionResolver;
use Preflow\Auth\Access\UndeclaredPermission;
use Preflow\Auth\AuthManager;
use Preflow\Auth\Authenticatable;
use Preflow\Auth\Tests\Fixtures\TestUser;

final class AccessTest extends TestCase
{
    private PermissionRegistry $registry;
    private AuthManager $auth;
    private string $log = '';

    protected function setUp(): void
    {
        $this->registry = new PermissionRegistry();
        $this->registry->declarePattern('page.*');
        foreach (['page.view', 'page.edit', 'page.publish'] as $name) {
            $this->registry->declare($name);
        }
        $this->auth = new AuthManager([], 'session');
        $this->log = (string) tempnam(sys_get_temp_dir(), 'acc');
        ini_set('error_log', $this->log);
    }

    protected function tearDown(): void
    {
        ini_restore('error_log');
        @unlink($this->log);
    }

    private function access(bool $debug = false, array $populations = []): Access
    {
        return new Access($this->auth, $this->registry, $debug, $populations);
    }

    public function test_staff_may_do_everything_by_default(): void
    {
        $access = $this->access(populations: ['folio' => 'staff']);
        $this->auth->setUser(new TestUser(), 'folio');

        $this->assertTrue($access->can('page.publish'));
    }

    public function test_members_are_denied_by_default(): void
    {
        $access = $this->access();
        $this->auth->setUser(new TestUser(), 'site'); // no population: members

        $this->assertFalse($access->can('page.publish'));
    }

    public function test_without_a_user_nothing_is_allowed(): void
    {
        $this->assertFalse($this->access(populations: ['folio' => 'staff'])->can('page.edit'));
    }

    public function test_undeclared_permission_throws_in_debug(): void
    {
        $this->auth->setUser(new TestUser(), 'folio');

        $this->expectException(UndeclaredPermission::class);
        $this->access(debug: true, populations: ['folio' => 'staff'])->can('pgae.edit');
    }

    public function test_undeclared_permission_is_denied_and_logged_once_in_production(): void
    {
        $access = $this->access(populations: ['folio' => 'staff']);
        $this->auth->setUser(new TestUser(), 'folio');

        $this->assertFalse($access->can('pgae.edit'));
        $this->assertFalse($access->can('pgae.edit'));

        $this->assertSame(1, substr_count((string) file_get_contents($this->log), 'pgae.edit'));
    }

    public function test_a_resolver_answers_once_set(): void
    {
        $access = $this->access(populations: ['folio' => 'staff']);
        $this->auth->setUser(new TestUser(roles: ['editor']), 'folio');
        $access->setResolver(new class implements PermissionResolver {
            public function allows(Authenticatable $user, string $population, string $permission, ?object $subject): bool
            {
                return $population === 'staff' && $user->hasRole('editor') && $permission === 'page.edit';
            }
        });

        $this->assertTrue($access->can('page.edit'));
        $this->assertFalse($access->can('page.publish'));
    }

    public function test_population_from_config_or_marked_staff_guard(): void
    {
        $access = $this->access(populations: ['site' => 'members']);
        $access->markStaffGuard('admin');
        $access->markStaffGuard('site'); // config wins

        $this->assertSame('staff', $access->populationOf('admin'));
        $this->assertSame('members', $access->populationOf('site'));
        $this->assertSame('members', $access->populationOf('other'));
    }

    public function test_without_auth_configured_everything_is_allowed(): void
    {
        $this->assertTrue((new Access(null, $this->registry, true, []))->can('anything.at.all'));
    }

    public function test_typo_in_a_family_throws_in_debug(): void
    {
        $this->auth->setUser(new \Preflow\Auth\Tests\Fixtures\TestUser(), 'folio');
        $this->expectException(\Preflow\Auth\Access\UndeclaredPermission::class);
        $this->access(debug: true, populations: ['folio' => 'staff'])->can('page.pubish');
    }

    public function test_disabled_permission_denied_not_thrown_in_debug(): void
    {
        $this->registry->declare('members.approve', 'Approve', 'Members');
        $this->registry->disable('members.approve', 'extension members');
        $this->auth->setUser(new TestUser(), 'folio');

        $this->assertFalse($this->access(debug: true, populations: ['folio' => 'staff'])->can('members.approve'));
        $this->assertSame('extension members', $this->registry->disabledReason('members.approve'));
        $this->assertNull($this->registry->disabledReason('page.view'));
    }

    public function test_disabled_logged_once(): void
    {
        $this->registry->declare('members.approve');
        $this->registry->disable('members.approve', 'extension members');
        $access = $this->access(populations: ['folio' => 'staff']);
        $this->auth->setUser(new TestUser(), 'folio');

        $access->can('members.approve');
        $access->can('members.approve');

        $log = (string) file_get_contents($this->log);
        $this->assertSame(1, substr_count($log, 'permission members.approve belongs to a disabled extension members'));
    }
}
