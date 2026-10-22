<?php

declare(strict_types=1);

namespace Preflow\Core\Throttle;

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
 * UTC, so a window spanning midnight starts over. hit() is a non-atomic
 * read-modify-write, so this is a soft limit. Rows of earlier days are
 * orphaned by the rotation; hit() prunes them (see prune()).
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
    private bool $pruned = false;

    public function __construct(
        private readonly DataManager $dm,
        private readonly string $key,
        string $storage = 'default',
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
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

    /** Daily-rotating, salted client hash from REMOTE_ADDR only (no forwarded headers). */
    public function client(ServerRequestInterface $request): string
    {
        if ($this->key === '' || $this->key === self::DEFAULT_KEY) {
            throw new \RuntimeException('Throttle needs APP_KEY (app.key) to be set; the default key would make client hashes reversible.');
        }
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        $ip = is_string($ip) && $ip !== '' ? $ip : 'unknown';

        return hash_hmac('sha256', $ip, $this->key . '|' . gmdate('Y-m-d'));
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
