<?php

declare(strict_types=1);

namespace Preflow\Members;

/**
 * The members hooks: a project adds its own steps to a member's life without watching routes.
 * Bound in the container as one registry; register listeners in a provider's boot():
 *
 *     $c->get(MemberEvents::class)->on(MemberEvents::DELETED, fn (array $p) => …);
 *
 * Listeners run in registration order and get one array, the event's payload:
 *
 * - REGISTERED  ['member' => DynamicRecord, 'request' => ?ServerRequestInterface] — a new account
 *   (the registration form, or Members::register() from a project; request null when none was given).
 * - CONFIRMED   ['member', 'request'] — the address is proven (the confirm link, or emailVerified);
 *   the admission has not decided yet.
 * - DECIDED     ['member', 'role' => 'member'|'pending'|'rejected'] — the admission (member, or pending
 *   for the owner) or the owner (member, rejected) decided.
 * - SIGNED_IN   ['member', 'request'] — the member pages or SignIn::in() signed the member in.
 * - DELETING    ['member', 'plan' => DeletePlan] — before the member's own deletion is carried out
 *   (the plan is not restricted). A listener returning false refuses it: nothing is deleted and
 *   DELETION_REFUSED follows. An exception here propagates (nothing has changed yet).
 * - DELETED     ['id' => string, 'member' => DynamicRecord (as it was), 'plan' => DeletePlan (applied)].
 * - DELETION_REFUSED ['member', 'plan', 'reason' => 'restricted'|'listener', 'notice_sent' => bool] —
 *   the own deletion was refused: a self rule (onSelfDelete) says restrict, or a DELETING listener
 *   said no. notice_sent: the owner's notice mail went out (MembersSettings::noticeEmail()).
 *   A listener returning true says it told the site itself; the member then reads that the site
 *   has been told.
 *
 * Every event but DELETING is an "after" event: an exception in its listener is logged and the
 * remaining listeners run; the member action stands.
 */
final class MemberEvents
{
    public const string REGISTERED = 'members.registered';
    public const string CONFIRMED = 'members.confirmed';
    public const string DECIDED = 'members.decided';
    public const string SIGNED_IN = 'members.signed_in';
    public const string DELETING = 'members.deleting';
    public const string DELETED = 'members.deleted';
    public const string DELETION_REFUSED = 'members.deletion_refused';

    public const array ALL = [
        self::REGISTERED, self::CONFIRMED, self::DECIDED, self::SIGNED_IN,
        self::DELETING, self::DELETED, self::DELETION_REFUSED,
    ];

    /** @var array<string, list<callable(array<string, mixed>): mixed>> */
    private array $listeners = [];

    /** @param callable(array<string, mixed>): mixed $listener */
    public function on(string $event, callable $listener): void
    {
        self::known($event);
        $this->listeners[$event][] = $listener;
    }

    /**
     * Runs the event's listeners in order. False only for DELETING when a listener returned false
     * (the later ones then don't run); true otherwise.
     *
     * @param array<string, mixed> $payload
     */
    public function dispatch(string $event, array $payload): bool
    {
        self::known($event);
        foreach ($this->listeners[$event] ?? [] as $listener) {
            if ($event === self::DELETING) {
                if ($listener($payload) === false) {
                    return false;
                }
                continue;
            }
            $this->runAfter($event, $listener, $payload);
        }

        return true;
    }

    /**
     * Runs an "after" event's listeners (all of them) and says whether any returned true — for
     * DELETION_REFUSED: whether a listener told the site.
     *
     * @param array<string, mixed> $payload
     */
    public function handled(string $event, array $payload): bool
    {
        self::known($event);
        if ($event === self::DELETING) {
            throw new \InvalidArgumentException('MemberEvents::handled(): DELETING may refuse; use dispatch().');
        }
        $handled = false;
        foreach ($this->listeners[$event] ?? [] as $listener) {
            $handled = $this->runAfter($event, $listener, $payload) === true || $handled;
        }

        return $handled;
    }

    /** @param array<string, mixed> $payload */
    private function runAfter(string $event, callable $listener, array $payload): mixed
    {
        try {
            return $listener($payload);
        } catch (\Throwable $e) {
            // The class and message only: a payload holds personal data.
            error_log(sprintf('members: a listener of %s failed (%s: %s)', $event, $e::class, $e->getMessage()));

            return null;
        }
    }

    private static function known(string $event): void
    {
        if (!in_array($event, self::ALL, true)) {
            throw new \InvalidArgumentException("Unknown members event \"{$event}\"; use a MemberEvents constant.");
        }
    }
}
