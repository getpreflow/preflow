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
 */
final class Throttle
{
    private const TABLE = 'preflow_throttle';

    private readonly TypeDefinition $type;
    private readonly \Closure $clock;

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
        } else {
            $record->set('hits', (int) $record->get('hits') + 1);
        }

        $this->dm->saveType($record, validate: false);
    }

    public function clear(string $purpose, string $client): void
    {
        $this->dm->deleteIn($this->type, $this->id($purpose, $client));
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
