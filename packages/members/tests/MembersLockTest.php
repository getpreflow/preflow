<?php

declare(strict_types=1);

namespace Preflow\Members\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Auth\PasswordHasherInterface;
use Preflow\Auth\Remember\RememberMe;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Members\MemberRoles;
use Preflow\Members\MemberState;
use Preflow\Members\Members;
use Preflow\Members\MembersConfig;
use Preflow\Members\Tests\Support\MembersScaffold;

/** The account lock, ending sessions and the last sign-in note on Members. */
final class MembersLockTest extends TestCase
{
    use MembersScaffold;

    protected function setUp(): void
    {
        $this->scaffold();
        $this->migrate();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    public function test_lock_sets_locked_at_and_keeps_roles_and_password(): void
    {
        $members = $this->members();
        $m = $this->member($members);
        $hash = (string) $m->get('password_hash');

        $this->assertFalse($members->isLocked($m));
        $members->lock($m);

        $this->assertTrue($members->isLocked($m));
        $this->assertNotSame('', (string) $m->get('locked_at'));
        $this->assertSame(['member'], MemberRoles::of($m));
        $this->assertSame($hash, $m->get('password_hash'));
        $this->assertSame(MemberState::Member, MemberState::fromRecord($m));
        $stored = $members->find((string) $m->getId());
        $this->assertNotNull($stored);
        $this->assertTrue($members->isLocked($stored));
        $this->assertSame(['member'], MemberRoles::of($stored));
    }

    public function test_lock_ends_sessions(): void
    {
        $members = $this->members();
        $m = $this->member($members);
        $id = (string) $m->getId();
        $remember = $this->container()->get(RememberMe::class);
        $cookie = $remember->issue(MembersConfig::GUARD, $id);
        $other = $remember->issue(MembersConfig::GUARD, 'someone-else');
        $this->assertSame('0', (string) $m->get('auth_epoch'));

        $members->lock($m);

        $this->assertSame('1', (string) $this->reload($members, $m)->get('auth_epoch'));
        $this->assertNull($remember->resolve(MembersConfig::GUARD, $cookie));
        $this->assertNotNull($remember->resolve(MembersConfig::GUARD, $other));
    }

    public function test_unlock_clears_locked_at_and_keeps_the_epoch(): void
    {
        $members = $this->members();
        $m = $this->member($members);
        $members->lock($m);
        $members->unlock($m);

        $fresh = $this->reload($members, $m);
        $this->assertFalse($members->isLocked($fresh));
        $this->assertSame('', (string) $fresh->get('locked_at'));
        $this->assertSame('1', (string) $fresh->get('auth_epoch'));
        $this->assertTrue($this->container()->get(PasswordHasherInterface::class)->verify('correct horse battery', (string) $fresh->get('password_hash')));
    }

    public function test_lock_twice_keeps_the_first_time(): void
    {
        $times = ['2026-10-27T10:00:00+00:00', '2026-10-27T11:00:00+00:00'];
        $members = $this->bare(static function () use (&$times): \DateTimeImmutable {
            return new \DateTimeImmutable(count($times) > 1 ? array_shift($times) : $times[0]);
        });
        $m = $members->register('ada@example.com', 'Ada', 'correct horse battery', 'en');
        $members->lock($m);
        $first = (string) $m->get('locked_at');
        $this->assertNotSame('', $first);

        $members->lock($m);

        $this->assertSame($first, (string) $m->get('locked_at'));
        $this->assertSame($first, (string) $this->reload($members, $m)->get('locked_at'));
        $this->assertSame('2', (string) $m->get('auth_epoch'), 'sessions ended again');
    }

    public function test_end_sessions_without_remember_bumps_the_epoch(): void
    {
        $members = $this->bare();
        $m = $members->register('ada@example.com', 'Ada', 'correct horse battery', 'en');

        $members->endSessions($m);

        $this->assertSame('1', (string) $this->reload($members, $m)->get('auth_epoch'));
    }

    public function test_note_sign_in_stamps_last_login_at(): void
    {
        $members = $this->bare(static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-10-27 09:30:00', new \DateTimeZone('UTC')));
        $m = $members->register('ada@example.com', 'Ada', 'correct horse battery', 'en');
        $this->assertSame('', (string) $m->get('last_login_at'));

        $members->noteSignIn($m);

        $this->assertSame('2026-10-27T09:30:00+00:00', $m->get('last_login_at'));
        $this->assertSame('2026-10-27T09:30:00+00:00', $this->reload($members, $m)->get('last_login_at'));
    }

    public function test_register_sets_lock_and_login_fields_empty(): void
    {
        $m = $this->members()->register('ada@example.com', 'Ada', 'correct horse battery', 'en');

        $stored = $this->members()->find((string) $m->getId());
        $this->assertNotNull($stored);
        $this->assertSame('', (string) $stored->get('locked_at'));
        $this->assertSame('', (string) $stored->get('last_login_at'));
    }

    public function test_register_refuses_lock_fields_in_extra(): void
    {
        foreach (['locked_at', 'last_login_at'] as $field) {
            try {
                $this->members()->register('ada@example.com', 'Ada', 'correct horse battery', 'en', [$field => 'x']);
                $this->fail($field);
            } catch (\LogicException $e) {
                $this->assertStringContainsString($field, $e->getMessage());
            }
        }
    }

    private function member(Members $members): DynamicRecord
    {
        $m = $members->register('ada@example.com', 'Ada', 'correct horse battery', 'en');
        $members->decide($m, 'member');

        return $m;
    }

    private function reload(Members $members, DynamicRecord $m): DynamicRecord
    {
        return $members->find((string) $m->getId()) ?? $this->fail('member gone');
    }

    /** A Members without the container's services (no RememberMe, no events). */
    private function bare(?\Closure $now = null): Members
    {
        return new Members($this->container()->get(DataManager::class), $this->container()->get(PasswordHasherInterface::class), $now);
    }

    private function members(): Members
    {
        return $this->container()->get(Members::class);
    }
}
