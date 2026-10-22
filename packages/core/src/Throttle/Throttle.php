<?php

declare(strict_types=1);

namespace Preflow\Core\Throttle;

use Preflow\Data\DataManager;
use Preflow\Data\Driver\MysqlDialect;
use Preflow\Data\Driver\PdoDriver;
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
 * UTC, so a window spanning midnight starts over. hit() is atomic: on SQL one
 * upsert counts the attempt (or restarts an expired window), on the JSON driver
 * an exclusive file lock in $lockDir guards the read-modify-write, so
 * concurrent requests never lose a hit. Rows of earlier days are orphaned by
 * the rotation; hit() prunes them (see prune()).
 *
 * client() refuses an empty or the well-known default key: a hash salted with a
 * public key could be brute-forced back to the IP.
 */
final class Throttle
{
    public const string DEFAULT_KEY = 'preflow-default-key-change-me!!';

    private const TABLE = 'preflow_throttle';

    private readonly TypeDefinition $type;
    private readonly \Closure $clock;
    private readonly string $lockDir;
    private bool $pruned = false;

    public function __construct(
        private readonly DataManager $dm,
        private readonly string $key,
        string $storage = 'default',
        ?\Closure $clock = null,
        ?string $lockDir = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
        $this->lockDir = $lockDir ?? sys_get_temp_dir();
        $this->type = new TypeDefinition(
            key: self::TABLE,
            table: self::TABLE,
            storage: $storage,
            fields: [
                'purpose' => new TypeFieldDefinition('purpose', 'string'),
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
        if ($this->key === '' || $this->key === self::DEFAULT_KEY) {
            throw new \RuntimeException('Throttle needs APP_KEY (app.key) to be set; the default key would make client hashes reversible.');
        }
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        $ip = is_string($ip) && $ip !== '' ? self::network($ip) : 'unknown';

        return hash_hmac('sha256', $ip, $this->key . '|' . gmdate('Y-m-d'));
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

    public function hit(string $purpose, string $client, int $windowSeconds): void
    {
        $id = $this->id($purpose, $client);
        $driver = $this->dm->driver($this->type->storage);

        if ($driver instanceof PdoDriver) {
            if (!$this->pruned) {
                $record = $this->dm->findIn($this->type, $id);
                if ($record === null || $this->expired($record, $windowSeconds)) {
                    $this->pruned = true;
                    $this->prune();
                }
            }
            $this->upsert($driver, $id, $purpose, $windowSeconds);

            return;
        }

        $this->locked($id, function () use ($id, $purpose, $windowSeconds): void {
            $record = $this->dm->findIn($this->type, $id);

            if ($record === null || $this->expired($record, $windowSeconds)) {
                $record = new DynamicRecord($this->type, [
                    'id' => $id,
                    'purpose' => $purpose,
                    'hits' => 1,
                    'window_start' => ($this->clock)(),
                ]);
                if (!$this->pruned) {
                    $this->pruned = true;
                    $this->prune();
                }
            } else {
                $record->set('hits', (int) $record->get('hits') + 1);
            }

            $this->dm->saveType($record, validate: false);
        });
    }

    /**
     * One statement: insert the row, or count the hit on it, restarting the
     * window when it has expired. MySQL applies the assignments left to right,
     * so hits is set first, while window_start still holds the old value.
     */
    private function upsert(PdoDriver $driver, string $id, string $purpose, int $windowSeconds): void
    {
        $dialect = $driver->dialect();
        $table = $dialect->quoteIdentifier(self::TABLE);
        $hits = $dialect->quoteIdentifier('hits');
        $start = $dialect->quoteIdentifier('window_start');
        $columns = implode(', ', array_map($dialect->quoteIdentifier(...), ['id', 'purpose', 'hits', 'window_start']));
        $update = "{$hits} = CASE WHEN ? >= {$start} + ? THEN 1 ELSE {$hits} + 1 END, "
            . "{$start} = CASE WHEN ? >= {$start} + ? THEN ? ELSE {$start} END";
        $conflict = $dialect instanceof MysqlDialect
            ? "ON DUPLICATE KEY UPDATE {$update}"
            : 'ON CONFLICT(' . $dialect->quoteIdentifier('id') . ") DO UPDATE SET {$update}";
        $now = ($this->clock)();

        $this->dm->execute(
            "INSERT INTO {$table} ({$columns}) VALUES (?, ?, 1, ?) {$conflict}",
            [$id, $purpose, $now, $now, $windowSeconds, $now, $windowSeconds, $now],
            $this->type->storage,
        );
    }

    /** Runs $fn holding an exclusive lock on throttle-{id}.lock in the lock directory. */
    private function locked(string $id, \Closure $fn): void
    {
        if (!is_dir($this->lockDir) && !@mkdir($this->lockDir, 0755, true) && !is_dir($this->lockDir)) {
            throw new \RuntimeException("Throttle lock directory {$this->lockDir} cannot be created.");
        }
        $path = $this->lockDir . '/throttle-' . $id . '.lock';
        $handle = fopen($path, 'c');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new \RuntimeException("Throttle lock {$path} cannot be taken.");
        }
        try {
            $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
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
