<?php

declare(strict_types=1);

namespace Preflow\Members\Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\RoleView;
use Preflow\Core\Context\SystemClock;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Members\Access\MemberAccess;
use Preflow\Members\MembersConfig;
use Preflow\Members\SyntheticMember;
use Preflow\Members\Tests\Support\MembersScaffold;
use Preflow\Members\Viewer;
use Preflow\Testing\ChecksFrameBalance;
use Nyholm\Psr7\ServerRequest;

/**
 * Viewing the site as a member role (view as role) shows a member without an account: Viewer::member()
 * gives a SyntheticMember whose profile fields are empty, so a page that reads the member's own data
 * shows empty values, never anybody's real data, and nothing about it can be saved.
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

    private function asRole(RoleView $view): RenderContext
    {
        return RenderContext::fromRequest(new ServerRequest('GET', 'https://site.test/members'), $this->container()->get(\Preflow\Core\Application::class)->site(), new SystemClock())
            ->asVisitor()
            ->asRole($view);
    }

    #[RunInSeparateProcess]
    public function test_a_synthetic_member_has_empty_profile_fields_and_cannot_be_saved(): void
    {
        $this->scaffold();
        $this->migrate();
        $viewer = $this->container()->get(Viewer::class);
        $dm = $this->container()->get(DataManager::class);
        $before = $dm->queryType(MembersConfig::TYPE)->get()->total();

        [$member, $name, $language, $staff, $access] = Contexts::run($this->asRole(new RoleView('member', ['member', 'gold'])), static fn (): array => [
            $viewer->member(), $viewer->name(), $viewer->language('de'), $viewer->isStaff(), $viewer->canAccess(),
        ]);

        $this->assertInstanceOf(SyntheticMember::class, $member);
        $this->assertSame('preflow:synthetic:member:gold,member', $member->getAuthId());
        $this->assertSame($member->getAuthId(), $member->getId());
        $this->assertSame(['gold', 'member'], $member->get('roles'));
        foreach (['name', 'email', 'language', 'password_hash', 'created_at', 'phone'] as $field) {
            $this->assertNull($member->get($field), $field);
            $this->assertNull($member->{$field}, $field . ' (as a template reads it)');
        }
        $this->assertSame(['roles' => ['gold', 'member']], $member->toArray());
        $this->assertSame(['', 'de', false, true], [$name, $language, $staff, $access], 'no name, the page language, no staff, admitted by its roles');

        foreach ([
            'set' => static fn () => $member->set('email', 'someone@site.test'),
            'remove' => static fn () => $member->remove('email'),
            'assign' => static function () use ($member): void {
                $member->email = 'someone@site.test';
            },
        ] as $what => $change) {
            try {
                $change();
                $this->fail("{$what}: a synthetic member is read-only");
            } catch (\LogicException $e) {
                $this->assertStringContainsString('read-only', $e->getMessage(), $what);
            }
        }
        $this->assertNull($member->get('email'));
        $this->assertNotInstanceOf(DynamicRecord::class, $member, 'no record: the data layer cannot save it');
        try {
            $dm->saveType($member); // @phpstan-ignore argument.type
            $this->fail('a synthetic member cannot be saved');
        } catch (\TypeError) {
        }
        $this->assertSame($before, $dm->queryType(MembersConfig::TYPE)->get()->total(), 'no member was written');
    }

    /** A staff role view is no member; a pending member role is a member without access. */
    #[RunInSeparateProcess]
    public function test_a_staff_role_view_has_no_member_and_a_pending_one_no_access(): void
    {
        $this->scaffold();
        $viewer = $this->container()->get(Viewer::class);

        [$member, $staff, $access] = Contexts::run($this->asRole(new RoleView('staff', ['admin'])), static fn (): array => [$viewer->member(), $viewer->isStaff(), $viewer->canAccess()]);
        $this->assertSame([null, true, false], [$member, $staff, $access], 'staff viewed as a role: no member, staff, and members.access is no staff grant');

        [$member, $access] = Contexts::run($this->asRole(new RoleView('member', ['pending'])), static fn (): array => [$viewer->member(), $viewer->canAccess()]);
        $this->assertInstanceOf(SyntheticMember::class, $member);
        $this->assertFalse($access, 'a pending member may not use the members area');
    }

    /** Without rbac, members' own resolver gives a member role view the grants an admitted member has. */
    public function test_member_access_grants_follow_the_roles(): void
    {
        $access = new MemberAccess(new \Preflow\Auth\Access\DefaultResolver());

        $this->assertSame([MembersConfig::PERMISSION], $access->grantsFor(['member'], 'members'));
        $this->assertSame([MembersConfig::PERMISSION], $access->grantsFor(['gold', 'member'], 'members'));
        $this->assertSame([], $access->grantsFor(['member', 'pending'], 'members'));
        $this->assertSame([], $access->grantsFor([], 'members'));
        $this->assertSame([], $access->grantsFor(['admin'], 'staff'), 'auth\'s default resolver lists no grants for a role: a staff role view gets nothing');
    }
}
