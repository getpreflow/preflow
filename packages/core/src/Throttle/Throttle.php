<?php

declare(strict_types=1);

namespace Preflow\Core\Throttle;

use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\WritePolicy;
use Preflow\Core\Context\Writes;
use Preflow\Data\CounterReset;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Fixed-window attempt limits, stored through the DataManager (table
 * preflow_throttle). Clients are identified by a salted hash that rotates
 * daily; a raw IP is never stored.
 *
 * Windows should be much shorter than a day: the client hash rotates at 00:00
 * UTC, so a window spanning midnight starts over. hit() counts through
 * DataManager::incrementIn(): on a storage with AtomicCounters (every built-in
 * driver: one upsert on SQL, the type's lock on JSON) concurrent requests never
 * lose a hit; on another storage the count is a read and a write, under the
 * type's lock when the storage has ExclusiveWrites.
 *
 * Retention (since 0.78): a row stores when its window ends (expires_at) and is deleted once it
 * has: by prune(), which the first hit that starts a window runs (once per instance), by
 * tooMany() and retryAfter() when they find it ended, and by `preflow throttle:prune` from cron
 * for a site that promises a retention however quiet it is. So a row lives for at most its
 * window plus the prune interval (the time until the next request that counts, or the next cron
 * run; `--every=30` makes it 30 seconds). An expired row never counts: hit() restarts an ended
 * window and tooMany() ignores it, so the bound is about privacy and size, not correctness.
 * Calling prune() on every request is unneeded, hit() already prunes. Rows of earlier days are
 * orphaned by the client hash's rotation and go the same way.
 *
 * Under the write policy (since 0.81): a hit is an 'increment' write, skipped in a preview or an
 * audit (the count stays as stored) and refused in a preview fragment; with $accounting (the
 * per-person AI limits) it counts under every policy, because the work it counts really happens.
 * Housekeeping deletes (prune() from hit(), an ended row found by tooMany()/retryAfter()) run
 * only where writes are allowed; elsewhere an ended row is simply not counted.
 *
 * client() refuses an empty or the well-known default key: a hash salted with a
 * public key could be brute-forced back to the IP. forPerson() (since 0.78) does the
 * same for a signed-in person's auth id, so the raw id is never stored either.
 */
final class Throttle
{
    public const string DEFAULT_KEY = 'preflow-default-key-change-me!!';

    /** The table (and type key) the rows are stored in. @since 0.78 public */
    public const string TABLE = 'preflow_throttle';

    /** Rows stored before 0.78 have no expires_at: prune() deletes them after this many seconds, as it did. */
    private const int LEGACY_RETENTION = 172800;

    private readonly TypeDefinition $type;
    private readonly \Closure $clock;
    private bool $pruned = false;

    /**
     * @param string|null $lockDir @deprecated ignored since 0.78: the storage guards the count
     *        (AtomicCounters, ExclusiveWrites); kept so existing calls keep working
     */
    public function __construct(
        private readonly DataManager $dm,
        private readonly string $key,
        string $storage = 'default',
        ?\Closure $clock = null,
        ?string $lockDir = null,
    ) {
        $this->clock = $clock ?? static fn (): int => Contexts::clock()->now()->getTimestamp();
        $this->type = new TypeDefinition(
            key: self::TABLE,
            table: self::TABLE,
            storage: $storage,
            fields: [
                'purpose' => new TypeFieldDefinition('purpose', 'string', exact: true),
                'hits' => new TypeFieldDefinition('hits', 'number'),
                'window_start' => new TypeFieldDefinition('window_start', 'number'),
                'expires_at' => new TypeFieldDefinition('expires_at', 'number'),
            ],
            idField: 'id',
            changeDates: false, // short-lived rows: nothing lists or sorts them by a change date (since 0.84.1)
        );
    }

    /**
     * The type the rows are stored as (table preflow_throttle: id, purpose, hits, window_start,
     * expires_at), for code that reads them, such as tests.
     *
     * @since 0.78
     */
    public function definition(): TypeDefinition
    {
        return $this->type;
    }

    /**
     * Daily-rotating, salted client hash from REMOTE_ADDR only (no forwarded headers). An
     * IPv6 address counts by its /64 prefix (one subscriber usually holds a whole /64);
     * IPv4, also IPv4-mapped IPv6, by the address.
     */
    public function client(ServerRequestInterface $request): string
    {
        $this->assertKey();
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        $ip = is_string($ip) && $ip !== '' ? self::network($ip) : 'unknown';

        return hash_hmac('sha256', $ip, $this->key . '|' . gmdate('Y-m-d', ($this->clock)()));
    }

    /**
     * The client string of a signed-in person (a per-person limit): the auth id hashed with the app
     * key. Stable, unlike client(): a window of an hour must not start over at midnight. The raw id
     * is never stored.
     *
     * @since 0.78
     */
    public function forPerson(string $authId): string
    {
        $this->assertKey();

        return hash_hmac('sha256', 'person|' . $authId, $this->key);
    }

    /**
     * Whether the app key can salt client and person hashes (not empty, not the default): without
     * it client() and forPerson() throw. Lets a caller refuse at boot instead of per request.
     *
     * @since 0.78
     */
    public function hasKey(): bool
    {
        return $this->key !== '' && $this->key !== self::DEFAULT_KEY;
    }

    private function assertKey(): void
    {
        if (!$this->hasKey()) {
            throw new \RuntimeException('Throttle needs APP_KEY (app.key) to be set; the default key would make client hashes reversible.');
        }
    }

    /** "2001:db8:1:2::/64" for an IPv6 address, the dotted address for IPv4; anything else as given. */
    private static function network(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return $ip;
        }
        $packed = (string) inet_pton($ip);
        if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return (string) inet_ntop(substr($packed, 12));
        }

        return inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
    }

    public function tooMany(string $purpose, string $client, int $max, int $windowSeconds): bool
    {
        $record = $this->current($purpose, $client, $windowSeconds);
        if ($record === null) {
            return false;
        }

        return (int) $record->get('hits') >= $max;
    }

    /**
     * Counts an attempt: the first of a window starts it at now, one at window_start +
     * $windowSeconds or later starts a new window (as tooMany() sees it expired); either stores
     * when the window ends (expires_at). A hit that starts a window prunes ended rows, once per
     * instance.
     *
     * @param bool $accounting counts under every write policy (Writes 'accounting', since 0.81.0):
     *                         for limits on work that happens in a preview too, such as paid AI calls
     * @return int the hits in the window, this one included (since 0.78; before, nothing)
     */
    public function hit(string $purpose, string $client, int $windowSeconds, bool $accounting = false): int
    {
        $now = ($this->clock)();
        $hits = $this->dm->incrementIn(
            $this->type,
            $this->id($purpose, $client),
            'hits',
            1,
            ['purpose' => $purpose, 'window_start' => $now, 'expires_at' => $now + $windowSeconds],
            new CounterReset('window_start', $now - $windowSeconds + 1, ['window_start' => $now, 'expires_at' => $now + $windowSeconds]),
            $accounting,
        );

        if ($hits === 1 && !$this->pruned && Writes::policy() === WritePolicy::Allow) {
            $this->pruned = true;
            $this->prune();
        }

        return $hits;
    }

    /**
     * Seconds until the stored window of $purpose and $client ends (at least 1), or 0 when there
     * is no row or its window has ended. It does not look at the count: the caller knows the limit.
     *
     * @since 0.78
     */
    public function retryAfter(string $purpose, string $client, int $windowSeconds): int
    {
        $record = $this->current($purpose, $client, $windowSeconds);
        if ($record === null) {
            return 0;
        }

        return max(1, (int) $record->get('window_start') + $windowSeconds - ($this->clock)());
    }

    public function clear(string $purpose, string $client): void
    {
        $this->dm->deleteIn($this->type, $this->id($purpose, $client));
    }

    /**
     * Delete the rows whose window has ended (expires_at at or before now; a row stored before
     * 0.78, without expires_at, once its window started two days ago). With $olderThanSeconds:
     * the rows whose window started before now - $olderThanSeconds, ended or not. Returns how
     * many were deleted.
     */
    public function prune(?int $olderThanSeconds = null): int
    {
        $now = ($this->clock)();
        $records = $olderThanSeconds === null
            ? [
                ...$this->dm->queryIn($this->type)->where('expires_at', '<=', $now)->get(),
                ...$this->dm->queryIn($this->type)->where('expires_at', null)->where('window_start', '<', $now - self::LEGACY_RETENTION)->get(),
            ]
            : [...$this->dm->queryIn($this->type)->where('window_start', '<', $now - $olderThanSeconds)->get()];
        $ids = array_map(static fn (DynamicRecord $record): string => (string) $record->getId(), $records);
        if ($ids !== []) {
            $this->dm->deleteManyIn($this->type, $ids);
        }

        return count($ids);
    }

    /** The row of $purpose and $client while its window runs; one whose window has ended is deleted. */
    private function current(string $purpose, string $client, int $windowSeconds): ?DynamicRecord
    {
        $id = $this->id($purpose, $client);
        $record = $this->dm->findIn($this->type, $id);
        if ($record !== null && $this->expired($record, $windowSeconds)) {
            if (Writes::policy() === WritePolicy::Allow) {
                $this->dm->deleteIn($this->type, $id); // housekeeping: never reported or refused
            }

            return null;
        }

        return $record;
    }

    private function id(string $purpose, string $client): string
    {
        return hash('sha256', $purpose . '|' . $client);
    }

    private function expired(DynamicRecord $record, int $windowSeconds): bool
    {
        return ($this->clock)() >= (int) $record->get('window_start') + $windowSeconds;
    }
}
