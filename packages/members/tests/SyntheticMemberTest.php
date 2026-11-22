<?php

declare(strict_types=1);

namespace Preflow\Members\Tests;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\RoleView;
use Preflow\Core\Context\SystemClock;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\StorageDriver;
use Preflow\Data\TypeRegistry;
use Preflow\Members\Access\MemberAccess;
use Preflow\Members\Http\AccountController;
use Preflow\Members\MembersConfig;
use Preflow\Members\Tests\Support\IntIdMemberRow;
use Preflow\Members\Tests\Support\MembersScaffold;
use Preflow\Members\Tests\Support\NumberingMembersDriver;
use Preflow\Members\Tests\Support\StringIdMemberRow;
use Preflow\Members\Viewer;
use Preflow\Testing\ChecksFrameBalance;

/**
 * Viewing the site as a member role (view as role) shows a member without an account:
 * Viewer::member() gives an in-memory record of the member type with every field empty and the
 * role view's reserved id, so a page that reads the member's own data shows empty values, never
 * anybody's real data. The role view's write policy stops saving or deleting it, and the account
 * page changes nothing.
 */
final class SyntheticMemberTest extends TestCase
{
    use ChecksFrameBalance;
    use MembersScaffold;

    protected function tearDown(): void
    {
        Contexts::reset();
        $this->removeScaffold();
    }

    private function visit(string $method = 'GET', string $path = '/members'): RenderContext
    {
        return RenderContext::fromRequest(new ServerRequest($method, 'https://site.test' . $path), $this->container()->get(\Preflow\Core\Application::class)->site(), new SystemClock());
    }

    private function asRole(RoleView $view, string $method = 'GET', string $path = '/members'): RenderContext
    {
        return $this->visit($method, $path)->asVisitor()->asRole($view);
    }

    /** @return list<array<string, mixed>> every stored member, as stored */
    private function stored(DataManager $dm): array
    {
        return array_map(static fn (DynamicRecord $r): array => $r->toArray(), $dm->queryType(MembersConfig::TYPE)->get()->items());
    }

    private function ada(): void
    {
        $this->saveRecord(MembersConfig::TYPE, ['uuid' => 'm-1', 'email' => 'ada@site.test', 'name' => 'Ada', 'password_hash' => 'x', 'roles' => ['member'], 'language' => 'en']);
    }

    #[RunInSeparateProcess]
    public function test_a_synthetic_member_has_empty_profile_fields_and_cannot_be_saved(): void
    {
        $this->scaffold();
        $this->migrate();
        $this->ada();
        $viewer = $this->container()->get(Viewer::class);
        $dm = $this->container()->get(DataManager::class);
        $before = $this->stored($dm);

        [$member, $name, $language, $staff, $access, $synthetic, $skipped] = Contexts::run($this->asRole(new RoleView('member', ['member', 'gold'])), static function () use ($viewer, $dm): array {
            $member = $viewer->member();
            $seen = [$member, $viewer->name(), $viewer->language('de'), $viewer->isStaff(), $viewer->canAccess(), $viewer->isSynthetic()];
            // Code that changes the member writes through the data layer: the role view's write policy skips it.
            $member?->set('email', 'someone@site.test');
            $dm->saveType($member, false);
            $dm->deleteType(MembersConfig::TYPE, (string) $member?->getId());
            $dm->deleteType(MembersConfig::TYPE, 'm-1');

            return [...$seen, Contexts::frame()->skippedWrites()];
        });

        $this->assertInstanceOf(DynamicRecord::class, $member);
        $this->assertSame(MembersConfig::TYPE, $member->getType()->key);
        $this->assertSame('preflow:synthetic:member:gold,member', $member->getId());
        $this->assertSame(['gold', 'member'], $member->get('roles'));
        foreach (['name', 'password_hash', 'language'] as $field) {
            $this->assertNull($member->get($field), $field);
        }
        $this->assertSame(['', 'de', false, true, true], [$name, $language, $staff, $access, $synthetic], 'no name, the page language, no staff, admitted by its roles, viewed as a role');
        $this->assertCount(3, $skipped, 'the save and both deletes were skipped and reported');
        $this->assertSame($before, $this->stored($dm), 'no member was written or deleted');

        // A visitor's own render is no role view.
        $this->assertFalse(Contexts::run($this->visit(), static fn (): bool => $viewer->isSynthetic()));
    }

    /** The account page under a member role view: a password change and a deletion change nothing. */
    #[RunInSeparateProcess]
    public function test_the_account_page_changes_nothing_under_a_role_view(): void
    {
        $this->scaffold();
        $this->migrate();
        $this->ada();
        $account = $this->container()->get(AccountController::class);
        $dm = $this->container()->get(DataManager::class);
        $before = $this->stored($dm);

        foreach ([
            'password' => ['/members/account/password', ['current' => '', 'password' => 'a-new-password-1', 'repeat' => 'a-new-password-1']],
            'delete' => ['/members/account/delete', ['current' => '', 'confirm' => '1']],
        ] as $action => [$path, $body]) {
            $status = Contexts::run($this->asRole(new RoleView('member', ['member']), 'POST', $path), static fn (): int => $account->{$action}(
                (new ServerRequest('POST', 'https://site.test' . $path))->withParsedBody($body),
            )->getStatusCode());
            $this->assertSame(422, $status, $action . ': refused as a wrong current password');
        }
        $this->assertSame($before, $this->stored($dm));
        $show = Contexts::run($this->asRole(new RoleView('member', ['member']), 'GET', '/members/account'), static fn (): int => $account->show(new ServerRequest('GET', 'https://site.test/members/account'))->getStatusCode());
        $this->assertSame(200, $show, 'the account page renders with empty fields');
    }

    /** A staff role view is no member; a pending member role is a member without access. */
    #[RunInSeparateProcess]
    public function test_a_staff_role_view_has_no_member_and_a_pending_one_no_access(): void
    {
        $this->scaffold();
        $viewer = $this->container()->get(Viewer::class);

        [$member, $staff, $access, $synthetic] = Contexts::run($this->asRole(new RoleView('staff', ['admin'])), static fn (): array => [$viewer->member(), $viewer->isStaff(), $viewer->canAccess(), $viewer->isSynthetic()]);
        $this->assertSame([null, true, false, true], [$member, $staff, $access, $synthetic], 'staff viewed as a role: no member, staff, and members.access is no staff grant');

        [$member, $access] = Contexts::run($this->asRole(new RoleView('member', ['pending'])), static fn (): array => [$viewer->member(), $viewer->canAccess()]);
        $this->assertInstanceOf(DynamicRecord::class, $member);
        $this->assertFalse($access, 'a pending member may not use the members area');
    }

    /**
     * A member type with a number id cannot stand for a role view's member: its reserved id would
     * compare as 0 in SQL. Members::synthetic() asks the data layer for the id kind
     * (DataManager::idKind()) and refuses a number id, however the type comes to have one.
     *
     * @return iterable<string, array{\Closure(string): array{TypeRegistry, StorageDriver}}>
     */
    public static function numberIdTypes(): iterable
    {
        yield 'a model file declaring a number id' => [static function (string $dir): array {
            $registry = new TypeRegistry($dir);
            $registry->define([
                'key' => MembersConfig::TYPE, 'table' => 'member', 'id_field' => 'id', 'label' => 'Members',
                'fields' => ['id' => ['type' => 'number'], 'email' => ['type' => 'string'], 'roles' => ['type' => 'string']],
            ]);

            return [$registry, new SqliteDriver(new \PDO('sqlite::memory:'))];
        }];
        yield 'a model class with an int id' => [static fn (string $dir): array => [
            new TypeRegistry($dir, null, [MembersConfig::TYPE => IntIdMemberRow::class]),
            new SqliteDriver(new \PDO('sqlite::memory:')),
        ]];
        yield 'a table whose key is an integer, the id undeclared' => [static function (string $dir): array {
            $registry = new TypeRegistry($dir);
            $registry->define(['key' => MembersConfig::TYPE, 'table' => 'member', 'label' => 'Members', 'fields' => ['email' => ['type' => 'string'], 'roles' => ['type' => 'string']]]);
            $pdo = new \PDO('sqlite::memory:');
            $pdo->exec('CREATE TABLE member (uuid INTEGER PRIMARY KEY, email TEXT, roles TEXT)');

            return [$registry, new SqliteDriver($pdo)];
        }];
        yield 'a storage that numbers members' => [static function (string $dir): array {
            $registry = new TypeRegistry($dir);
            $registry->define(['key' => MembersConfig::TYPE, 'table' => 'member', 'label' => 'Members', 'fields' => ['email' => ['type' => 'string'], 'roles' => ['type' => 'string']]]);

            return [$registry, new NumberingMembersDriver(new SqliteDriver(new \PDO('sqlite::memory:')))];
        }];
    }

    /** @param \Closure(string): array{TypeRegistry, StorageDriver} $setup */
    #[DataProvider('numberIdTypes')]
    public function test_a_member_type_with_a_number_id_is_refused(\Closure $setup): void
    {
        $dir = sys_get_temp_dir() . '/members_intid_' . bin2hex(random_bytes(4));
        @mkdir($dir, 0777, true);
        try {
            [$registry, $driver] = $setup($dir);
            $dm = new DataManager(drivers: ['default' => $driver], typeRegistry: $registry);
            $members = new \Preflow\Members\Members($dm, new \Preflow\Auth\NativePasswordHasher());

            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('is not a string');
            $members->synthetic(new RoleView('member', ['member']));
        } finally {
            @rmdir($dir);
        }
    }

    /** A member type whose model class has a string id (or a model file with an undeclared or string id) stands for a role view's member. */
    public function test_a_member_type_with_a_string_id_is_synthesized(): void
    {
        $dir = sys_get_temp_dir() . '/members_strid_' . bin2hex(random_bytes(4));
        @mkdir($dir, 0777, true);
        try {
            foreach ([
                'class' => new TypeRegistry($dir, null, [MembersConfig::TYPE => StringIdMemberRow::class]),
                'declared' => (static function () use ($dir): TypeRegistry {
                    $registry = new TypeRegistry($dir);
                    $registry->define(['key' => MembersConfig::TYPE, 'table' => 'member', 'label' => 'Members', 'fields' => ['uuid' => ['type' => 'string'], 'email' => ['type' => 'string'], 'roles' => ['type' => 'string']]]);

                    return $registry;
                })(),
            ] as $case => $registry) {
                $dm = new DataManager(drivers: ['default' => new SqliteDriver(new \PDO('sqlite::memory:'))], typeRegistry: $registry);
                $member = (new \Preflow\Members\Members($dm, new \Preflow\Auth\NativePasswordHasher()))->synthetic(new RoleView('member', ['member']));

                $this->assertSame('preflow:synthetic:member:member', $member->getId(), $case);
                $this->assertSame(['member'], $member->get('roles'), $case);
            }
        } finally {
            @rmdir($dir);
        }
    }

    /** Without rbac, members' own resolver gives a member role view the grants an admitted member has. */
    public function test_member_access_grants_follow_the_roles(): void
    {
        $access = new MemberAccess(new \Preflow\Auth\Access\DefaultResolver());

        $this->assertTrue($access->allowsRoles(['member'], 'members', MembersConfig::PERMISSION, null));
        $this->assertTrue($access->allowsRoles(['gold', 'member'], 'members', MembersConfig::PERMISSION, null));
        $this->assertFalse($access->allowsRoles(['member'], 'members', MembersConfig::DECIDE, null));
        $this->assertFalse($access->allowsRoles(['member', 'pending'], 'members', MembersConfig::PERMISSION, null));
        $this->assertFalse($access->allowsRoles([], 'members', MembersConfig::PERMISSION, null));
        $this->assertFalse($access->allowsRoles(['admin'], 'staff', 'page.edit', null), 'auth\'s default resolver cannot answer for roles: a staff role view gets nothing');
    }

}
