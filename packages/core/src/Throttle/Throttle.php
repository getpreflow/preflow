<?php

declare(strict_types=1);

namespace Preflow\Core\Throttle;

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
 * type's lock when the storage has ExclusiveWrites. Rows of earlier days are
 * orphaned by the rotation; hit() prunes them (see prune()).
 *
 * client() refuses an empty or the well-known default key: a hash salted with a
 * public key could be brute-forced back to the IP. forPerson() (since 0.78) does the
 * same for a signed-in person's auth id, so the raw id is never stored either.
 */
final class Throttle
{
    public const string DEFAULT_KEY = 'preflow-default-key-change-me!!';

    private const TABLE = 'preflow_throttle';

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
        $this->clock = $clock ?? static fn (): int => time();
        $this->type = new TypeDefinition(
            key: self::TABLE,
            table: self::TABLE,
            storage: $storage,
            fields: [
                'purpose' => new TypeFieldDefinition('purpose', 'string', exact: true),
                'hits' => new TypeFieldDefinition('hits', 'number'),
                'window_start' => new TypeFieldDefinition('window_start', 'number'),
            ],
            idField: 'id',
        );
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

        return hash_hmac('sha256', $ip, $this->key . '|' . gmdate('Y-m-d'));
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
        $record = $this->dm->findIn($this->type, $this->id($purpose, $client));
        if ($record === null || $this->expired($record, $windowSeconds)) {
            return false;
        }

        return (int) $record->get('hits') >= $max;
    }

    /**
     * Counts an attempt: the first of a window starts it at now, one at window_start +
     * $windowSeconds or later starts a new window (as tooMany() sees it expired). A hit that
     * starts a window prunes old rows, once per instance.
     *
     * @return int the hits in the window, this one included (since 0.78; before, nothing)
     */
    public function hit(string $purpose, string $client, int $windowSeconds): int
    {
        $now = ($this->clock)();
        $hits = $this->dm->incrementIn(
            $this->type,
            $this->id($purpose, $client),
            'hits',
            1,
            ['purpose' => $purpose, 'window_start' => $now],
            new CounterReset('window_start', $now - $windowSeconds + 1, ['window_start' => $now]),
        );

        if ($hits === 1 && !$this->pruned) {
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
        $record = $this->dm->findIn($this->type, $this->id($purpose, $client));
        if ($record === null || $this->expired($record, $windowSeconds)) {
            return 0;
        }

        return max(1, (int) $record->get('window_start') + $windowSeconds - ($this->clock)());
    }

    public function clear(string $purpose, string $client): void
    {
        $this->dm->deleteIn($this->type, $this->id($purpose, $client));
    }

    /**
     * Delete rows whose window started before now - $olderThanSeconds
     * (default two days). Returns how many were deleted.
     */
    public function prune(int $olderThanSeconds = 172800): int
    {
        $cutoff = ($this->clock)() - $olderThanSeconds;
        $count = 0;
        foreach ($this->dm->queryIn($this->type)->where('window_start', '<', $cutoff)->get() as $record) {
            $this->dm->deleteIn($this->type, (string) $record->getId());
            $count++;
        }

        return $count;
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
