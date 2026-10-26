<?php

declare(strict_types=1);

namespace Preflow\Members\Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DeletePlan;
use Preflow\Data\DynamicRecord;
use Preflow\Members\MemberEvents;
use Preflow\Members\Members;
use Preflow\Members\SignIn;
use Preflow\Members\Tests\Support\MemberFlows;
use Preflow\Members\Tests\Support\MembersScaffold;
use Psr\Http\Message\ServerRequestInterface;

/** The members hooks: listeners in order, "after" failures logged, DELETING may refuse; each event once with its payload. */
final class MemberEventsTest extends TestCase
{
    use MemberFlows;
    use MembersScaffold;

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $events = [];

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            $this->removeScaffold();
        }
    }

    public function test_listeners_run_in_registration_order(): void
    {
        $events = new MemberEvents();
        $order = [];
        $events->on(MemberEvents::REGISTERED, static function (array $p) use (&$order): void { $order[] = 'a' . $p['n']; });
        $events->on(MemberEvents::REGISTERED, static function (array $p) use (&$order): void { $order[] = 'b' . $p['n']; });
        $events->on(MemberEvents::CONFIRMED, static function () use (&$order): void { $order[] = 'other'; });

        $this->assertTrue($events->dispatch(MemberEvents::REGISTERED, ['n' => 1]));
        $this->assertSame(['a1', 'b1'], $order);
    }

    public function test_an_exception_in_an_after_listener_is_logged_and_the_rest_run(): void
    {
        $log = sys_get_temp_dir() . '/member_events_' . bin2hex(random_bytes(4)) . '.log';
        $old = ini_set('error_log', $log);
        try {
            $events = new MemberEvents();
            $ran = false;
            $events->on(MemberEvents::DELETED, static function (): void { throw new \RuntimeException('listener broke'); });
            $events->on(MemberEvents::DELETED, static function () use (&$ran): void { $ran = true; });

            $this->assertTrue($events->dispatch(MemberEvents::DELETED, []));
            $this->assertTrue($ran, 'the next listener still runs');
            $this->assertStringContainsString('members: a listener of members.deleted failed (RuntimeException: listener broke)', (string) file_get_contents($log));
        } finally {
            ini_set('error_log', (string) $old);
            @unlink($log);
        }
    }

    public function test_deleting_returning_false_refuses_and_stops_later_listeners(): void
    {
        $events = new MemberEvents();
        $later = false;
        $events->on(MemberEvents::DELETING, static fn (): bool => true);
        $events->on(MemberEvents::DELETING, static fn (): bool => false);
        $events->on(MemberEvents::DELETING, static function () use (&$later): void { $later = true; });

        $this->assertFalse($events->dispatch(MemberEvents::DELETING, []));
        $this->assertFalse($later);
        $this->assertTrue((new MemberEvents())->dispatch(MemberEvents::DELETING, []), 'no listener: no refusal');
    }

    public function test_false_from_an_after_listener_refuses_nothing(): void
    {
        $events = new MemberEvents();
        $events->on(MemberEvents::SIGNED_IN, static fn (): bool => false);

        $this->assertTrue($events->dispatch(MemberEvents::SIGNED_IN, []));
    }

    public function test_an_exception_in_a_deleting_listener_propagates(): void
    {
        $events = new MemberEvents();
        $events->on(MemberEvents::DELETING, static function (): void { throw new \RuntimeException('no'); });

        $this->expectException(\RuntimeException::class);
        $events->dispatch(MemberEvents::DELETING, []);
    }

    public function test_handled_says_whether_a_listener_returned_true(): void
    {
        $events = new MemberEvents();
        $this->assertFalse($events->handled(MemberEvents::DELETION_REFUSED, []));
        $events->on(MemberEvents::DELETION_REFUSED, static fn (): ?bool => null);
        $this->assertFalse($events->handled(MemberEvents::DELETION_REFUSED, []));
        $events->on(MemberEvents::DELETION_REFUSED, static fn (): bool => true);
        $this->assertTrue($events->handled(MemberEvents::DELETION_REFUSED, []));
    }

    public function test_an_unknown_event_is_an_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new MemberEvents())->on('members.registerd', static fn () => null);
    }

    #[RunInSeparateProcess]
    public function test_registration_confirmation_and_sign_in_raise_their_events_once(): void
    {
        $this->flowSite();
        $this->listen();

        $this->post('/members/register', ['name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'password' => self::PASSWORD, 'consent' => '1']);
        $this->assertSame([MemberEvents::REGISTERED], $this->names());
        [, $registered] = $this->events[0];
        $this->assertSame('ada@example.com', $registered['member']->get('email'));
        $this->assertInstanceOf(ServerRequestInterface::class, $registered['request']);
        $this->assertSame('/members/register', $registered['request']->getUri()->getPath());

        $this->events = [];
        $this->post('/members/confirm', ['token' => $this->linkToken(0, 'confirm')]);
        $this->assertSame([MemberEvents::CONFIRMED, MemberEvents::DECIDED, MemberEvents::SIGNED_IN], $this->names());
        [, $confirmed] = $this->events[0];
        $this->assertSame('ada@example.com', $confirmed['member']->get('email'));
        $this->assertSame('/members/confirm', $confirmed['request']->getUri()->getPath());
        [, $decided] = $this->events[1];
        $this->assertSame('member', $decided['role']);
        $this->assertSame('ada@example.com', $decided['member']->get('email'));
        [, $signedIn] = $this->events[2];
        $this->assertSame('ada@example.com', $signedIn['member']->get('email'));
        $this->assertInstanceOf(ServerRequestInterface::class, $signedIn['request']);

        $this->post('/members/logout', []);
        $this->events = [];
        $this->post('/members/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);
        $this->assertSame([MemberEvents::SIGNED_IN], $this->names());
    }

    #[RunInSeparateProcess]
    public function test_a_failed_sign_in_raises_nothing(): void
    {
        $this->flowSite();
        $this->makeMember();
        $this->listen();

        $this->post('/members/login', ['email' => 'ada@example.com', 'password' => 'wrong wrong wrong']);

        $this->assertSame([], $this->names());
    }

    #[RunInSeparateProcess]
    public function test_a_verified_registration_raises_registered_confirmed_decided(): void
    {
        $this->flowSite();
        $this->listen();

        $m = $this->flow->container()->get(Members::class)->register('ada@example.com', 'Ada Lovelace', self::PASSWORD, 'en', emailVerified: true);
        $this->flow->container()->get(SignIn::class)->in($m, $this->request('GET', '/'));

        $this->assertSame([MemberEvents::REGISTERED, MemberEvents::CONFIRMED, MemberEvents::DECIDED, MemberEvents::SIGNED_IN], $this->names());
    }

    #[RunInSeparateProcess]
    public function test_the_owners_decision_raises_decided(): void
    {
        $this->flowSite();
        $m = $this->makeMember('ada@example.com', 'pending');
        $this->listen();

        $this->flow->container()->get(Members::class)->decide($m, 'rejected');

        $this->assertSame([MemberEvents::DECIDED], $this->names());
        $this->assertSame('rejected', $this->events[0][1]['role']);
    }

    #[RunInSeparateProcess]
    public function test_deleting_the_account_raises_deleting_then_deleted_with_what_a_project_needs(): void
    {
        $this->flowSite();
        $m = $this->makeMember();
        $id = (string) $m->getId();
        $this->post('/members/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);
        $this->listen();
        $this->flow->container()->get(MemberEvents::class)->on(MemberEvents::DELETED, static function (): void {
            throw new \RuntimeException('project step broke');
        });

        $response = $this->post('/members/account/delete', ['current' => self::PASSWORD, 'confirm' => '1']);

        $this->assertSame(303, $response->getStatusCode(), 'the deletion stands');
        $this->assertNull($this->flow->container()->get(Members::class)->find($id));
        $this->assertSame([MemberEvents::DELETING, MemberEvents::DELETED], $this->names());
        [, $deleting] = $this->events[0];
        $this->assertSame($id, (string) $deleting['member']->getId());
        $this->assertInstanceOf(DeletePlan::class, $deleting['plan']);
        [, $deleted] = $this->events[1];
        $this->assertSame($id, $deleted['id']);
        $this->assertInstanceOf(DynamicRecord::class, $deleted['member'], 'the record as it was');
        $this->assertSame('ada@example.com', $deleted['member']->get('email'));
        $this->assertSame(['member' => [$id]], $deleted['plan']->deletes());
        $this->assertStringContainsString('members: a listener of members.deleted failed (RuntimeException: project step broke)', (string) file_get_contents($this->dir . '/error.log'));
    }

    /** Records every event of the flow app's registry. */
    private function listen(): void
    {
        $registry = $this->flow->container()->get(MemberEvents::class);
        foreach (MemberEvents::ALL as $event) {
            $registry->on($event, function (array $payload) use ($event): void {
                $this->events[] = [$event, $payload];
            });
        }
    }

    /** @return list<string> */
    private function names(): array
    {
        return array_map(static fn (array $e): string => $e[0], $this->events);
    }
}
