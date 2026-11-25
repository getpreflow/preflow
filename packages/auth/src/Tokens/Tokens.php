<?php

declare(strict_types=1);

namespace Preflow\Auth\Tokens;

use Preflow\Auth\Support\FileLock;
use Preflow\Core\Context\Contexts;
use Preflow\Data\Condition;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;

/**
 * Single-use, expiring tokens for links sent by email (address confirmation,
 * password reset), stored through the DataManager (table auth_tokens).
 *
 * A token is 32 random bytes, base64url; only its SHA-256 hash is stored, as
 * the row id, with a purpose, a subject (who it is for) and expires_at.
 * consume() spends a token at most once (DataManager::deleteWhen()): on SQL one
 * DELETE decides by its affected rows, on another storage an exclusive file lock
 * in $lockDir guards the read and the delete (only for a token that exists; the
 * file is removed once the row is gone). In a preview (writes skipped) a valid
 * token gives its subject as on a visit, on every storage, and stays stored.
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
        $this->clock = $clock ?? static fn (): int => Contexts::clock()->now()->getTimestamp();
        $this->lockDir = $lockDir ?? sys_get_temp_dir();
        $this->type = new TypeDefinition(
            key: self::TABLE,
            table: self::TABLE,
            storage: $storage,
            fields: [
                'purpose' => new TypeFieldDefinition('purpose', 'string', exact: true),
                'subject' => new TypeFieldDefinition('subject', 'string', exact: true),
                'expires_at' => new TypeFieldDefinition('expires_at', 'number'),
            ],
            idField: 'id',
            changeDates: false, // short-lived rows: nothing lists or sorts them by a change date (since 0.84.1)
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
        $consumed = $this->dm->deleteWhen(
            $this->type,
            $id,
            // Built when the check runs, so "now" is read then (under the lock on a storage without
            // SQL). The id comparison confirms the stored hash exactly, whatever the lookup matched.
            fn (): Condition => Condition::all(
                Condition::where('id', '=', $id),
                Condition::where('purpose', '=', $purpose),
                Condition::where('expires_at', '>', ($this->clock)()),
            ),
            // The file lock is taken only for a token that exists; its file goes once the row has.
            fn (\Closure $step): ?DynamicRecord => FileLock::run($this->lockDir, 'tokens-' . $id, function () use ($step, $id): array {
                $record = $step();

                return [$record, $this->dm->findIn($this->type, $id) === null];
            }),
        );

        return $consumed === null ? null : (string) $consumed->get('subject');
    }

    /** Delete every token of $purpose for $subject. Returns how many were deleted. */
    public function revokeAll(string $purpose, string $subject): int
    {
        return $this->deleteAll($this->dm->queryIn($this->type)->where('purpose', $purpose)->where('subject', $subject)->get());
    }

    /** Delete expired tokens. Returns how many were deleted. */
    public function prune(): int
    {
        $now = ($this->clock)();

        return $this->deleteAll($this->dm->queryIn($this->type)->where('expires_at', '<=', $now)->get());
    }

    /**
     * Deletes the found $records, in as few statements as the storage allows
     * (DataManager::deleteManyIn()); returns how many.
     *
     * @param iterable<DynamicRecord> $records
     */
    private function deleteAll(iterable $records): int
    {
        $ids = [];
        foreach ($records as $record) {
            $ids[] = (string) $record->getId();
        }
        $this->dm->deleteManyIn($this->type, $ids);

        return count($ids);
    }
}
