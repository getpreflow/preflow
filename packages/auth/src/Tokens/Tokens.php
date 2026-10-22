<?php

declare(strict_types=1);

namespace Preflow\Auth\Tokens;

use Preflow\Data\DataManager;
use Preflow\Data\Driver\PdoDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;

/**
 * Single-use, expiring tokens for links sent by email (address confirmation,
 * password reset), stored through the DataManager (table auth_tokens).
 *
 * A token is 32 random bytes, base64url; only its SHA-256 hash is stored, as
 * the row id, with a purpose, a subject (who it is for) and expires_at.
 * consume() spends a token at most once: on SQL one DELETE decides by its
 * affected rows, on the JSON driver an exclusive file lock in $lockDir guards
 * the read and the delete.
 */
final class Tokens
{
    private const TABLE = 'auth_tokens';

    private readonly TypeDefinition $type;
    private readonly \Closure $clock;
    private readonly string $lockDir;

    public function __construct(
        private readonly DataManager $dm,
        private readonly string $storage = 'default',
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
                'subject' => new TypeFieldDefinition('subject', 'string'),
                'expires_at' => new TypeFieldDefinition('expires_at', 'number'),
            ],
            idField: 'id',
        );
    }

    /** A new token for $subject, valid for $ttlSeconds. Only its hash is stored. */
    public function issue(string $purpose, string $subject, int $ttlSeconds): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $this->dm->saveType(new DynamicRecord($this->type, [
            'id' => hash('sha256', $token),
            'purpose' => $purpose,
            'subject' => $subject,
            'expires_at' => ($this->clock)() + $ttlSeconds,
        ]), validate: false);

        return $token;
    }

    /**
     * The token's subject, deleting the token; null when it is unknown,
     * expired or for another purpose (then nothing is deleted).
     */
    public function consume(string $token, string $purpose): ?string
    {
        $id = hash('sha256', $token);
        $driver = $this->dm->driver($this->storage);

        if ($driver instanceof PdoDriver) {
            $record = $this->dm->findIn($this->type, $id);
            if ($record === null) {
                return null;
            }
            $dialect = $driver->dialect();
            $deleted = $this->dm->execute(
                'DELETE FROM ' . $dialect->quoteIdentifier(self::TABLE)
                    . ' WHERE ' . $dialect->quoteIdentifier('id') . ' = ? AND ' . $dialect->quoteIdentifier('purpose') . ' = ?'
                    . ' AND ' . $dialect->quoteIdentifier('expires_at') . ' > ?',
                [$id, $purpose, ($this->clock)()],
                $this->storage,
            );

            return $deleted === 1 ? (string) $record->get('subject') : null;
        }

        return $this->locked($id, function () use ($id, $purpose): ?string {
            $record = $this->dm->findIn($this->type, $id);
            // The file lookup found the hash; hash_equals confirms the stored one exactly.
            if ($record === null
                || !hash_equals((string) $record->getId(), $id)
                || $record->get('purpose') !== $purpose
                || (int) $record->get('expires_at') <= ($this->clock)()) {
                return null;
            }
            $this->dm->deleteIn($this->type, $id);

            return (string) $record->get('subject');
        });
    }

    /** Delete every token of $purpose for $subject. Returns how many were deleted. */
    public function revokeAll(string $purpose, string $subject): int
    {
        $driver = $this->dm->driver($this->storage);
        if ($driver instanceof PdoDriver) {
            $dialect = $driver->dialect();

            return $this->dm->execute(
                'DELETE FROM ' . $dialect->quoteIdentifier(self::TABLE)
                    . ' WHERE ' . $dialect->quoteIdentifier('purpose') . ' = ? AND ' . $dialect->quoteIdentifier('subject') . ' = ?',
                [$purpose, $subject],
                $this->storage,
            );
        }

        $count = 0;
        foreach ($this->dm->queryIn($this->type)->where('purpose', $purpose)->where('subject', $subject)->get() as $record) {
            $this->dm->deleteIn($this->type, (string) $record->getId());
            $count++;
        }

        return $count;
    }

    /** Delete expired tokens. Returns how many were deleted. */
    public function prune(): int
    {
        $now = ($this->clock)();
        $driver = $this->dm->driver($this->storage);
        if ($driver instanceof PdoDriver) {
            $dialect = $driver->dialect();

            return $this->dm->execute(
                'DELETE FROM ' . $dialect->quoteIdentifier(self::TABLE) . ' WHERE ' . $dialect->quoteIdentifier('expires_at') . ' <= ?',
                [$now],
                $this->storage,
            );
        }

        $count = 0;
        foreach ($this->dm->queryIn($this->type)->where('expires_at', '<=', $now)->get() as $record) {
            $this->dm->deleteIn($this->type, (string) $record->getId());
            $count++;
        }

        return $count;
    }

    /**
     * Runs $fn holding an exclusive lock on tokens-{id}.lock in the lock directory.
     *
     * @template T
     * @param \Closure(): T $fn
     * @return T
     */
    private function locked(string $id, \Closure $fn): mixed
    {
        if (!is_dir($this->lockDir) && !@mkdir($this->lockDir, 0755, true) && !is_dir($this->lockDir)) {
            throw new \RuntimeException("Tokens lock directory {$this->lockDir} cannot be created.");
        }
        $path = $this->lockDir . '/tokens-' . $id . '.lock';
        $handle = fopen($path, 'c');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new \RuntimeException("Tokens lock {$path} cannot be taken.");
        }
        try {
            return $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
