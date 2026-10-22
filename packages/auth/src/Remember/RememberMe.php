<?php

declare(strict_types=1);

namespace Preflow\Auth\Remember;

use Preflow\Auth\Support\FileLock;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\PdoDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;

/**
 * Remember-me tokens for session guards, stored through the DataManager
 * (table auth_remember).
 *
 * The cookie value is {selector}:{validator}, 16 and 32 random bytes, base64url.
 * The selector is the row id; only the validator's SHA-256 hash is stored.
 * Each use rotates the validator under the same selector (the expiry stays).
 * A known selector with a wrong validator means an old value was replayed
 * after the owner used it: every row of that user for the guard is deleted.
 * On SQL a conditional UPDATE decides the rotation by its affected rows, so
 * two uses of one value never both rotate; on the JSON driver an exclusive
 * file lock in $lockDir guards the read and the write.
 */
final class RememberMe
{
    private const TABLE = 'auth_remember';
    private const COOKIE = '/^([A-Za-z0-9_-]{22}):([A-Za-z0-9_-]{43})$/';

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
                'guard' => new TypeFieldDefinition('guard', 'string'),
                'user_id' => new TypeFieldDefinition('user_id', 'string'),
                'validator_hash' => new TypeFieldDefinition('validator_hash', 'string'),
                'expires_at' => new TypeFieldDefinition('expires_at', 'number'),
            ],
            idField: 'id',
        );
    }

    /** A new cookie value remembering $userId on $guard for $ttlSeconds. */
    public function issue(string $guard, string $userId, int $ttlSeconds = 30 * 86400): string
    {
        $selector = self::random(16);
        $validator = self::random(32);

        $this->dm->saveType(new DynamicRecord($this->type, [
            'id' => $selector,
            'guard' => $guard,
            'user_id' => $userId,
            'validator_hash' => hash('sha256', $validator),
            'expires_at' => ($this->clock)() + $ttlSeconds,
        ]), validate: false);

        return $selector . ':' . $validator;
    }

    /**
     * Who $cookie remembers on $guard, with the rotated cookie value to send back;
     * null when it is malformed, unknown, expired, for another guard or lost the
     * rotation to a concurrent use. A wrong validator revokes all of the user's rows.
     *
     * @return array{userId: string, cookie: string}|null
     */
    public function resolve(string $guard, string $cookie): ?array
    {
        if (preg_match(self::COOKIE, $cookie, $m) !== 1) {
            return null;
        }
        [, $selector, $validator] = $m;
        $driver = $this->dm->driver($this->storage);

        if ($driver instanceof PdoDriver) {
            $record = $this->dm->findIn($this->type, $selector);
            if ($record === null) {
                return null;
            }
            $next = $this->check($record, $selector, $guard, $validator);
            if (!is_string($next)) {
                return $next;
            }
            $dialect = $driver->dialect();
            $q = $dialect->quoteIdentifier(...);
            $rotated = $this->dm->execute(
                'UPDATE ' . $q(self::TABLE) . ' SET ' . $q('validator_hash') . ' = ?'
                    . ' WHERE ' . $q('id') . ' = ? AND ' . $q('validator_hash') . ' = ? AND ' . $q('expires_at') . ' > ?',
                [hash('sha256', $next), $selector, hash('sha256', $validator), ($this->clock)()],
                $this->storage,
            );

            return $rotated === 1 ? ['userId' => (string) $record->get('user_id'), 'cookie' => $selector . ':' . $next] : null;
        }

        // Unknown selectors (any cookie a client makes up) never get a lock file.
        if ($this->dm->findIn($this->type, $selector) === null) {
            return null;
        }

        return FileLock::run($this->lockDir, 'remember-' . $selector, function () use ($selector, $guard, $validator): array {
            $record = $this->dm->findIn($this->type, $selector);
            if ($record === null) {
                return [null, true];
            }
            $next = $this->check($record, $selector, $guard, $validator);
            if (!is_string($next)) {
                return [$next, $this->dm->findIn($this->type, $selector) === null];
            }
            $this->dm->saveType(new DynamicRecord($this->type, [
                'id' => $selector,
                'guard' => $guard,
                'user_id' => (string) $record->get('user_id'),
                'validator_hash' => hash('sha256', $next),
                'expires_at' => (int) $record->get('expires_at'),
            ]), validate: false);

            return [['userId' => (string) $record->get('user_id'), 'cookie' => $selector . ':' . $next], false];
        });
    }

    /** Delete the row of $cookie on $guard (signing out on this device). */
    public function forget(string $guard, string $cookie): void
    {
        if (preg_match(self::COOKIE, $cookie, $m) !== 1) {
            return;
        }
        $selector = $m[1];
        $driver = $this->dm->driver($this->storage);

        if ($driver instanceof PdoDriver) {
            $dialect = $driver->dialect();
            $this->dm->execute(
                'DELETE FROM ' . $dialect->quoteIdentifier(self::TABLE)
                    . ' WHERE ' . $dialect->quoteIdentifier('id') . ' = ? AND ' . $dialect->quoteIdentifier('guard') . ' = ?',
                [$selector, $guard],
                $this->storage,
            );

            return;
        }

        if ($this->dm->findIn($this->type, $selector) === null) {
            return;
        }
        FileLock::run($this->lockDir, 'remember-' . $selector, function () use ($selector, $guard): array {
            $record = $this->dm->findIn($this->type, $selector);
            if ($record !== null && hash_equals((string) $record->getId(), $selector) && $record->get('guard') === $guard) {
                $this->dm->deleteIn($this->type, $selector);
            }

            return [null, $this->dm->findIn($this->type, $selector) === null];
        });
    }

    /** Delete every row of $userId on $guard. Returns how many were deleted. */
    public function revokeAll(string $guard, string $userId): int
    {
        $driver = $this->dm->driver($this->storage);
        if ($driver instanceof PdoDriver) {
            $dialect = $driver->dialect();

            return $this->dm->execute(
                'DELETE FROM ' . $dialect->quoteIdentifier(self::TABLE)
                    . ' WHERE ' . $dialect->quoteIdentifier('guard') . ' = ? AND ' . $dialect->quoteIdentifier('user_id') . ' = ?',
                [$guard, $userId],
                $this->storage,
            );
        }

        $count = 0;
        foreach ($this->dm->queryIn($this->type)->where('guard', $guard)->where('user_id', $userId)->get() as $record) {
            $this->dm->deleteIn($this->type, (string) $record->getId());
            $count++;
        }

        return $count;
    }

    /** Delete expired rows. Returns how many were deleted. */
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
     * The next validator when $record accepts $validator on $guard; otherwise null
     * (an expired row is deleted, a wrong validator revokes all of the user's rows).
     */
    private function check(DynamicRecord $record, string $selector, string $guard, string $validator): ?string
    {
        // The file lookup found the selector; hash_equals confirms the stored one exactly.
        if (!hash_equals((string) $record->getId(), $selector) || $record->get('guard') !== $guard) {
            return null;
        }
        if ((int) $record->get('expires_at') <= ($this->clock)()) {
            $this->dm->deleteIn($this->type, $selector);

            return null;
        }
        if (!hash_equals((string) $record->get('validator_hash'), hash('sha256', $validator))) {
            $this->revokeAll($guard, (string) $record->get('user_id'));

            return null;
        }

        return self::random(32);
    }

    private static function random(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
