<?php

declare(strict_types=1);

namespace Preflow\Codes;

/**
 * The codes hooks: a project adds its own steps to a redemption without wrapping the call.
 * Bound in the container as one registry; register listeners in a provider's boot():
 *
 *     $c->get(CodeEvents::class)->on(CodeEvents::REDEEMED, fn (array $p) => …, 'invitation');
 *
 * Listeners get one array, the event's payload:
 *
 * - REDEEMED ['code' => CodeRecord (after the use was counted), 'purpose', 'redeemer' => ?string,
 *   'payload' => array (what the code grants)]. Runs inside redeem(), after the use is counted and
 *   the redemption stored. An exception in a listener undoes both (the use is given back, the
 *   redemption deleted), is no throttle failure, and is rethrown from redeem().
 * - REVOKED  the same payload (redeemer null), after the revoke is stored. An exception propagates;
 *   the revoke stands.
 *
 * A listener registered with a purpose runs only for that purpose, one without for all purposes.
 * Listeners run in registration order across both kinds.
 */
final class CodeEvents
{
    public const string REDEEMED = 'codes.redeemed';
    public const string REVOKED = 'codes.revoked';

    public const array ALL = [self::REDEEMED, self::REVOKED];

    /** @var array<string, list<array{0: callable(array<string, mixed>): mixed, 1: ?string}>> */
    private array $listeners = [];

    /** @param callable(array<string, mixed>): mixed $listener */
    public function on(string $event, callable $listener, ?string $purpose = null): void
    {
        self::known($event);
        $this->listeners[$event][] = [$listener, $purpose];
    }

    /** @param array<string, mixed> $payload */
    public function dispatch(string $event, array $payload): void
    {
        self::known($event);
        foreach ($this->listeners[$event] ?? [] as [$listener, $purpose]) {
            if ($purpose === null || $purpose === ($payload['purpose'] ?? null)) {
                $listener($payload);
            }
        }
    }

    private static function known(string $event): void
    {
        if (!in_array($event, self::ALL, true)) {
            throw new \InvalidArgumentException("Unknown codes event \"{$event}\"; use a CodeEvents constant.");
        }
    }
}
