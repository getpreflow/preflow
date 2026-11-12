<?php

declare(strict_types=1);

namespace Preflow\Codes;

use Preflow\Core\Throttle\Throttle;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\PdoDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\SortDirection;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Validation\ValidationException;

/**
 * Codes that grant something for one purpose (config/codes.php): readable codes (a book code,
 * typed by a visitor in any case, with spaces or dashes) and random ones (128 bits, base64url,
 * for links). Codes are bearer tokens: they are matched with hash_equals after a lookup by
 * purpose, and redemptions record who redeemed, never an IP address or user agent.
 *
 * Codes are the data type "code" (storage alias "codes"); redemptions are the internal table
 * code_redemption on the same storage, never in the TypeCatalog, so neither is exported.
 * redeem() counts a use atomically, so max_uses holds under concurrent redeems: on a SQL
 * storage one conditional UPDATE, on the JSON driver a file lock in $lockDir around the
 * read-modify-write.
 */
final class Codes
{
    public const string STORAGE = 'codes';
    public const string REDEMPTIONS = 'code_redemption';

    /** Random codes tried before issue() gives up on finding a free one. */
    private const int ISSUE_ATTEMPTS = 3;

    /** @var array<string, Purpose> */
    private readonly array $purposes;
    private readonly int $throttleMax;
    private readonly int $throttleWindow;
    private readonly \Closure $clock;
    private readonly string $lockDir;
    private readonly \Closure $randomCode;
    private ?TypeDefinition $redemptions = null;

    /**
     * @param array<string, mixed> $config config/codes.php: purposes (name => label, readable) and throttle (max, window)
     * @param (\Closure(): \DateTimeImmutable)|null $clock
     * @param (\Closure(): string)|null $randomCode makes a random code (default: 128 bits, base64url)
     */
    public function __construct(
        private readonly DataManager $dm,
        private readonly Throttle $throttle,
        array $config = [],
        ?\Closure $clock = null,
        ?string $lockDir = null,
        private readonly ?CodeEvents $events = null,
        ?\Closure $randomCode = null,
    ) {
        $purposes = [];
        foreach ((array) ($config['purposes'] ?? []) as $name => $purpose) {
            $purpose = (array) $purpose;
            $purposes[(string) $name] = new Purpose(
                (string) $name,
                (string) ($purpose['label'] ?? $name),
                (bool) ($purpose['readable'] ?? false),
                isset($purpose['ttl']) && (int) $purpose['ttl'] > 0 ? (int) $purpose['ttl'] : null,
            );
        }
        $this->purposes = $purposes;
        $this->throttleMax = (int) ($config['throttle']['max'] ?? 10);
        $this->throttleWindow = (int) ($config['throttle']['window'] ?? 900);
        $this->lockDir = $lockDir ?? sys_get_temp_dir();
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
        $this->randomCode = $randomCode ?? static fn (): string => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }

    /** @return array<string, Purpose> by name */
    public function purposes(): array
    {
        return $this->purposes;
    }

    public function purpose(string $name): Purpose
    {
        return $this->purposes[$name]
            ?? throw new CodeRefused(CodeRefused::UNKNOWN_PURPOSE, "Unknown code purpose [{$name}]; add it to config/codes.php 'purposes'.");
    }

    /**
     * Issues a code and returns it as stored: a readable one when $code is given (normalised,
     * 4–64 characters A–Z and 0–9, only for purposes that allow readable codes), a random one
     * otherwise. Codes are unique within their purpose. Without $expiresAt a code lives as long
     * as the purpose's ttl (days) says, if it has one. A random code another code of the purpose
     * has already (found, or refused by the storage) is drawn again.
     *
     * A random code can be bound to a $recipient (an e-mail address, normalised to lower case):
     * it then holds exactly one use, whatever $maxUses says, and redeems only for that address.
     *
     * @param array<string, mixed> $payload what the code grants, handed back on a successful redeem
     */
    public function issue(
        string $purpose,
        array $payload = [],
        ?string $code = null,
        string $label = '',
        ?\DateTimeImmutable $expiresAt = null,
        ?int $maxUses = null,
        ?string $recipient = null,
    ): string {
        $this->purpose($purpose);
        if ($recipient !== null) {
            if ($code !== null) {
                throw new \InvalidArgumentException('A readable code cannot be bound to a recipient.');
            }
            $recipient = self::normalizeRecipient($recipient);
            if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
                throw new \InvalidArgumentException('A recipient needs an e-mail address.');
            }
            $maxUses = 1;
        }
        $form = $code === null ? 'random' : 'readable';
        $type = $this->codeType();
        for ($attempt = 1; ; $attempt++) {
            $retry = $form === 'random' && $attempt < self::ISSUE_ATTEMPTS;
            $columns = $this->prepare($purpose, $code, $expiresAt);
            if ($form === 'random' && $this->find($columns['code'], $purpose) !== null) {
                if ($retry) {
                    continue;
                }
                throw new CodeRefused(CodeRefused::TAKEN, "A code like this already exists for purpose [{$purpose}].");
            }
            try {
                $this->dm->saveType(DynamicRecord::fromArray($type, $columns + [
                    $type->idField => bin2hex(random_bytes(16)),
                    'label' => $label,
                    'max_uses' => $maxUses,
                    'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'recipient' => $recipient,
                ]), false);

                return $columns['code'];
            } catch (ValidationException $e) {
                // Issued by someone else between the check and the write.
                if (!isset($e->errors()['code'])) {
                    throw $e;
                }
                if (!$retry) {
                    throw new CodeRefused(CodeRefused::TAKEN, "A code like this already exists for purpose [{$purpose}].", $e);
                }
            }
        }
    }

    /**
     * The columns every new code gets, whoever makes it (issue(), an admin): purpose, code, form,
     * expires_at (the given time, else the purpose's ttl from now, else none), uses, revoked_at and
     * created_at. A given $readable code goes through checkReadable(); without one a random code is
     * drawn (not checked for uniqueness: issue() draws again when it is taken).
     *
     * @return array{purpose: string, code: string, form: string, expires_at: ?string, uses: int, revoked_at: null, created_at: int}
     */
    public function prepare(string $purpose, ?string $readable, ?\DateTimeImmutable $expiresAt = null): array
    {
        $definition = $this->purpose($purpose);
        $now = ($this->clock)();
        if ($expiresAt === null && $definition->ttl !== null) {
            $expiresAt = $now->modify("+{$definition->ttl} days");
        }

        return [
            'purpose' => $purpose,
            'code' => $readable === null ? ($this->randomCode)() : $this->checkReadable($purpose, $readable),
            'form' => $readable === null ? 'random' : 'readable',
            'expires_at' => $expiresAt === null ? null : self::storeTime($expiresAt),
            'uses' => 0,
            'revoked_at' => null,
            'created_at' => $now->getTimestamp(),
        ];
    }

    /**
     * The readable code as it is stored (upper case, spaces and dashes removed), or a CodeRefused:
     * unknown purpose, a purpose without readable codes, not 4 to 64 letters A-Z and digits, or a
     * code the purpose already has.
     */
    public function checkReadable(string $purpose, string $code): string
    {
        if (!$this->purpose($purpose)->readable) {
            throw new CodeRefused(CodeRefused::NOT_READABLE, "Code purpose [{$purpose}] does not allow readable codes.");
        }
        $code = self::normalizeReadable($code);
        if ($code === null || preg_match('/^[A-Z0-9]{4,64}$/', $code) !== 1) {
            throw new CodeRefused(CodeRefused::FORMAT, 'A readable code needs 4 to 64 letters A-Z and digits (spaces and dashes are ignored).');
        }
        if ($this->find($code, $purpose) !== null) {
            throw new CodeRefused(CodeRefused::TAKEN, "A code like this already exists for purpose [{$purpose}].");
        }

        return $code;
    }

    private function codeType(): TypeDefinition
    {
        return $this->dm->getTypeRegistry()?->get('code')
            ?? throw new \RuntimeException('preflow/codes: the type "code" is not defined (no config/data.php?).');
    }

    /**
     * Whether the code would be accepted for the purpose now; counts no use. With a $client
     * (Throttle::client()) it is throttled like redeem(): same key, and every failure counts.
     * A code bound to a recipient: with $recipient it must match (else wrong_recipient); without,
     * the check passes and Result::$recipient tells whom the code is for.
     */
    public function check(string $code, string $purpose, ?string $client = null, ?string $recipient = null): Result
    {
        $this->purpose($purpose);
        if ($client !== null && $this->tooMany($purpose, $client)) {
            return Result::failure('throttled');
        }

        $result = $this->evaluate($code, $purpose, $recipient, false);
        if (!$result->ok && $client !== null) {
            $this->throttle->hit("codes.{$purpose}", $client, $this->throttleWindow);
        }

        return $result;
    }

    private function tooMany(string $purpose, string $client): bool
    {
        return $this->throttle->tooMany("codes.{$purpose}", $client, $this->throttleMax, $this->throttleWindow);
    }

    /** Unknown, revoked, expired, used up, then the recipient: wrong_recipient is only told for a live code. */
    private function evaluate(string $code, string $purpose, ?string $recipient, bool $redeeming): Result
    {
        $record = $this->find($code, $purpose);
        if ($record === null) {
            return Result::failure('unknown');
        }
        $id = (string) $record->getId();
        if ((string) ($record->get('revoked_at') ?? '') !== '') {
            return Result::failure('revoked', $id);
        }
        if ($this->expired($record->get('expires_at'))) {
            return Result::failure('expired', $id);
        }
        $max = $record->get('max_uses');
        if ($max !== null && $max !== '' && (int) $record->get('uses') >= (int) $max) {
            return Result::failure('used_up', $id);
        }
        $bound = (string) ($record->get('recipient') ?? '');
        $bound = $bound === '' ? null : $bound;
        if ($bound !== null) {
            $typed = $recipient === null ? null : self::normalizeRecipient($recipient);
            $matches = $typed !== null && hash_equals($bound, $typed);
            if (!$matches && ($typed !== null || $redeeming)) {
                return Result::failure('wrong_recipient', $id, $bound);
            }
        }
        $payload = json_decode((string) ($record->get('payload') ?? ''), true);

        return Result::success(is_array($payload) ? $payload : [], $id, $bound);
    }

    /**
     * Redeems the code: counts a use and records the redemption. With a $client (Throttle::client()),
     * too many failures from it within the window refuse even a valid code, and every failure counts.
     * A code bound to a recipient redeems only with that address in $recipient (any case, surrounding
     * spaces ignored); otherwise wrong_recipient, which counts for the throttle but uses nothing up.
     */
    public function redeem(string $code, string $purpose, ?string $redeemer = null, ?string $client = null, ?string $recipient = null): Result
    {
        $this->purpose($purpose);
        if ($client !== null && $this->tooMany($purpose, $client)) {
            return Result::failure('throttled');
        }

        $result = $this->evaluate($code, $purpose, $recipient, true);
        if ($result->ok && !$this->countUse((string) $result->codeId)) {
            $result = Result::failure('used_up', $result->codeId);
        }
        if (!$result->ok) {
            if ($client !== null) {
                $this->throttle->hit("codes.{$purpose}", $client, $this->throttleWindow);
            }

            return $result;
        }

        $codeId = (string) $result->codeId;
        $redemptionId = bin2hex(random_bytes(16));
        $written = false;
        try {
            $this->dm->saveType(new DynamicRecord($this->redemptionType(), [
                'id' => $redemptionId,
                'code_id' => $result->codeId,
                'purpose' => $purpose,
                'redeemer' => $redeemer,
                'redeemed_at' => ($this->clock)()->getTimestamp(),
            ]), false);
            $written = true;
            $this->dispatch(CodeEvents::REDEEMED, $codeId, $redeemer);
        } catch (\Throwable $e) {
            // The redeem did not happen: give the use back and drop the redemption if it was
            // written, no throttle hit. A failing undo is logged and never hides the original exception.
            $undo = [fn () => $this->uncountUse($codeId)];
            if ($written) {
                $undo[] = fn () => $this->dm->deleteIn($this->redemptionType(), $redemptionId);
            }
            foreach ($undo as $step) {
                try {
                    $step();
                } catch (\Throwable $failure) {
                    error_log('preflow/codes: undoing a failed redeem failed: ' . $failure::class . ': ' . $failure->getMessage());
                }
            }

            throw $e;
        }

        return $result;
    }

    private function dispatch(string $event, string $codeId, ?string $redeemer): void
    {
        if ($this->events === null) {
            return;
        }
        $code = $this->get($codeId);
        if ($code === null) {
            return;
        }
        $this->events->dispatch($event, [
            'code' => $code,
            'purpose' => $code->purpose,
            'redeemer' => $redeemer,
            'payload' => $code->payload,
        ]);
    }

    /**
     * Counts one use if the code still has one left; false when it is used up. The check
     * evaluate() made may be stale by now, so the limit is enforced here, atomically.
     */
    private function countUse(string $codeId): bool
    {
        $type = $this->dm->getTypeRegistry()?->get('code')
            ?? throw new \RuntimeException('preflow/codes: the type "code" is not defined (no config/data.php?).');
        $driver = $this->dm->driver($type->storage);

        if ($driver instanceof PdoDriver) {
            [$table, $id, $uses, $count, $limit] = $this->sql($driver, $type);

            return $this->dm->execute(
                "UPDATE {$table} SET {$uses} = {$count} + 1 WHERE {$id} = ? AND ({$limit} IS NULL OR {$count} < {$limit})",
                [$codeId],
                $type->storage,
            ) === 1;
        }

        return $this->locked($codeId, function () use ($codeId): bool {
            $record = $this->dm->findType('code', $codeId);
            if ($record === null) {
                return false;
            }
            $max = $record->get('max_uses');
            if ($max !== null && $max !== '' && (int) $record->get('uses') >= (int) $max) {
                return false;
            }
            $record->set('uses', (int) $record->get('uses') + 1);
            $this->dm->saveType($record, false);

            return true;
        });
    }

    /** Gives one counted use back, atomically and never below zero (the undo of countUse()). */
    private function uncountUse(string $codeId): void
    {
        $type = $this->dm->getTypeRegistry()?->get('code')
            ?? throw new \RuntimeException('preflow/codes: the type "code" is not defined (no config/data.php?).');
        $driver = $this->dm->driver($type->storage);

        if ($driver instanceof PdoDriver) {
            [$table, $id, $uses, $count] = $this->sql($driver, $type);
            $this->dm->execute(
                "UPDATE {$table} SET {$uses} = {$count} - 1 WHERE {$id} = ? AND {$count} > 0",
                [$codeId],
                $type->storage,
            );

            return;
        }

        $this->locked($codeId, function () use ($codeId): bool {
            $record = $this->dm->findType('code', $codeId);
            if ($record === null || (int) $record->get('uses') <= 0) {
                return false;
            }
            $record->set('uses', (int) $record->get('uses') - 1);
            $this->dm->saveType($record, false);

            return true;
        });
    }

    /**
     * Quoted names and the numeric expressions for the use counting UPDATEs, from the driver's
     * Dialect: TEXT on tables made before typed columns (holding '' or '3.0'), numbers after, so
     * both are read as whole numbers, never compared as strings or with ''. A max of NULL or ''
     * is no limit; uses of NULL or '' count as 0.
     *
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string} table, id, uses, count, limit (NULL for none)
     */
    private function sql(PdoDriver $driver, TypeDefinition $type): array
    {
        $dialect = $driver->dialect();
        $uses = $dialect->quoteIdentifier('uses');

        return [
            $dialect->quoteIdentifier($type->table),
            $dialect->quoteIdentifier($type->idField),
            $uses,
            'COALESCE(' . $dialect->wholeNumber($uses) . ', 0)',
            $dialect->wholeNumber($dialect->quoteIdentifier('max_uses')),
        ];
    }

    /** Runs $fn holding an exclusive lock on codes-{id}.lock in the lock directory. */
    private function locked(string $codeId, \Closure $fn): bool
    {
        if (!is_dir($this->lockDir) && !@mkdir($this->lockDir, 0755, true) && !is_dir($this->lockDir)) {
            throw new \RuntimeException("Codes lock directory {$this->lockDir} cannot be created.");
        }
        $path = $this->lockDir . '/codes-' . $codeId . '.lock';
        $handle = fopen($path, 'c');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new \RuntimeException("Codes lock {$path} cannot be taken.");
        }
        try {
            return $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** Revokes the code; a revoked code keeps its first revocation time. Writes that one column only. */
    public function revoke(string $codeId): void
    {
        $record = $this->dm->findType('code', $codeId)
            ?? throw new \InvalidArgumentException("Unknown code [{$codeId}].");
        if ((string) ($record->get('revoked_at') ?? '') !== '') {
            return;
        }
        $at = ($this->clock)()->format(DATE_ATOM);
        $changed = $this->writeColumns($codeId, ['revoked_at' => $at], static fn (DynamicRecord $r): bool => (string) ($r->get('revoked_at') ?? '') === '');
        if ($changed) {
            $this->dispatch(CodeEvents::REVOKED, $codeId, null);
        }
    }

    /**
     * Sets columns of a code without writing the rest of the record back, so a use counted at
     * the same time is never overwritten: one UPDATE on a SQL storage, the JSON driver's
     * read-modify-write under the code's lock. $applies decides on the fresh record whether to write.
     *
     * On SQL, $extra (given the driver and type) adds conditions to the UPDATE's WHERE, so the
     * checks made on a read are part of the write.
     *
     * @param array<string, string|int|null> $values column => value
     * @param \Closure(DynamicRecord): bool $applies
     * @param (\Closure(PdoDriver, TypeDefinition): array{0: string, 1: list<mixed>})|null $extra SQL condition (starting with " AND ") and its parameters
     */
    private function writeColumns(string $codeId, array $values, \Closure $applies, ?\Closure $extra = null): bool
    {
        $type = $this->codeType();
        $driver = $this->dm->driver($type->storage);

        if ($driver instanceof PdoDriver) {
            $dialect = $driver->dialect();
            $table = $dialect->quoteIdentifier($type->table);
            $id = $dialect->quoteIdentifier($type->idField);
            $set = [];
            foreach (array_keys($values) as $column) {
                $set[] = $dialect->quoteIdentifier($column) . ' = ?';
            }
            $guard = '';
            if (array_key_exists('revoked_at', $values)) {
                $col = $dialect->quoteIdentifier('revoked_at');
                $guard = " AND ({$col} IS NULL OR {$col} = '')";
            }

            $params = [...array_values($values), $codeId];
            if ($extra !== null) {
                [$sql, $more] = $extra($driver, $type);
                $guard .= $sql;
                array_push($params, ...$more);
            }

            return $this->dm->execute('UPDATE ' . $table . ' SET ' . implode(', ', $set) . " WHERE {$id} = ?{$guard}", $params, $type->storage) === 1;
        }

        return $this->locked($codeId, function () use ($codeId, $values, $applies): bool {
            $record = $this->dm->findType('code', $codeId);
            if ($record === null || !$applies($record)) {
                return false;
            }
            foreach ($values as $column => $value) {
                $record->set($column, $value);
            }
            $this->dm->saveType($record, false);

            return true;
        });
    }

    /**
     * Oldest first. Redemptions in the same second are ordered by id, not by insertion: ids are
     * random and no storage promises insertion order (MySQL returns ties in any order).
     *
     * @return list<array{id: string, redeemer: ?string, redeemed_at: int}>
     */
    public function redemptions(string $codeId): array
    {
        $rows = [];
        $query = $this->dm->queryIn($this->redemptionType())
            ->where('code_id', '=', $codeId)
            ->orderBy('redeemed_at', SortDirection::Asc, numeric: true)
            ->orderBy('id', SortDirection::Asc);
        foreach ($query->get()->items() as $record) {
            $redeemer = $record->get('redeemer');
            $rows[] = [
                'id' => (string) $record->getId(),
                'redeemer' => $redeemer === null || $redeemer === '' ? null : (string) $redeemer,
                'redeemed_at' => (int) $record->get('redeemed_at'),
            ];
        }

        return $rows;
    }

    /** The code as a record, or null when the purpose has no such code. */
    public function record(string $code, string $purpose): ?CodeRecord
    {
        $this->purpose($purpose);
        $record = $this->find($code, $purpose);

        return $record === null ? null : $this->toRecord($record);
    }

    public function get(string $codeId): ?CodeRecord
    {
        $record = $this->dm->findType('code', $codeId);

        return $record === null ? null : $this->toRecord($record);
    }

    /**
     * The codes bound to an address, newest first (codes from before created_at last); by default
     * only open ones (not revoked, used up or expired).
     *
     * @return list<CodeRecord>
     */
    public function forRecipient(string $email, ?string $purpose = null, bool $open = true): array
    {
        $email = self::normalizeRecipient($email);
        if ($email === '') {
            return [];
        }
        $query = $this->dm->queryType('code')->where('recipient', '=', $email);
        if ($purpose !== null) {
            $query->where('purpose', '=', $purpose);
        }
        $rows = $query->get()->items();
        usort($rows, static function (DynamicRecord $a, DynamicRecord $b): int {
            $x = $a->get('created_at');
            $y = $b->get('created_at');
            $x = $x === null || $x === '' ? null : (int) $x;
            $y = $y === null || $y === '' ? null : (int) $y;
            if ($x !== $y) {
                return $x === null ? 1 : ($y === null ? -1 : $y <=> $x);
            }

            return strcmp((string) $a->getId(), (string) $b->getId());
        });
        $now = ($this->clock)();
        $records = [];
        foreach ($rows as $row) {
            $record = $this->toRecord($row);
            if (!$open || $record->isOpen($now)) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * Gives a code a new expiry. Only a code that could still work: unknown, revoked and used-up
     * codes, and dates that are not in the future, are refused.
     */
    public function renew(string $codeId, \DateTimeImmutable $expiresAt): void
    {
        $record = $this->dm->findType('code', $codeId)
            ?? throw new \InvalidArgumentException("Unknown code [{$codeId}].");
        if ((string) ($record->get('revoked_at') ?? '') !== '') {
            throw new \InvalidArgumentException('A revoked code cannot be renewed.');
        }
        $max = $record->get('max_uses');
        if ($max !== null && $max !== '' && (int) $record->get('uses') >= (int) $max) {
            throw new \InvalidArgumentException('A used-up code cannot be renewed.');
        }
        if ($expiresAt <= ($this->clock)()) {
            throw new \InvalidArgumentException('A code can only be renewed until a time in the future.');
        }
        $written = $this->writeColumns(
            $codeId,
            ['expires_at' => self::storeTime($expiresAt)],
            static fn (DynamicRecord $fresh): bool => (string) ($fresh->get('revoked_at') ?? '') === ''
                && ($fresh->get('max_uses') === null || $fresh->get('max_uses') === '' || (int) $fresh->get('uses') < (int) $fresh->get('max_uses')),
            self::liveGuard(null),
        );
        if (!$written) {
            throw new CodeRefused(CodeRefused::CHANGED, 'The code changed meanwhile; reload it and try again.');
        }
    }

    /**
     * The conditions of a write to a code that is still live: not revoked, not used up and, with
     * $maxUses, the uses so far within it (see writeColumns()).
     *
     * @return \Closure(PdoDriver, TypeDefinition): array{0: string, 1: list<mixed>}
     */
    private static function liveGuard(?int $maxUses): \Closure
    {
        return static function (PdoDriver $driver, TypeDefinition $type) use ($maxUses): array {
            $dialect = $driver->dialect();
            $uses = $dialect->quoteIdentifier('uses');
            $count = 'COALESCE(' . $dialect->wholeNumber($uses) . ', 0)';
            $limit = $dialect->wholeNumber($dialect->quoteIdentifier('max_uses'));
            $revoked = $dialect->quoteIdentifier('revoked_at');
            $sql = " AND ({$revoked} IS NULL OR {$revoked} = '') AND ({$limit} IS NULL OR {$count} < {$limit})";

            return $maxUses === null ? [$sql, []] : [$sql . " AND {$count} <= ?", [$maxUses]];
        };
    }

    /**
     * Changes what an admin may change on a stored code: its label (null: none), expiry (null: never)
     * and max uses (null: unlimited); everything else, and the use count, stays. The refusals of
     * checkUpdate() are part of the write (under the lock on the JSON driver, as conditions of the
     * UPDATE on SQL): a redeem or revoke landing in between refuses with CodeRefused::CHANGED.
     */
    public function update(string $codeId, ?string $label, ?\DateTimeImmutable $expiresAt, ?int $maxUses): void
    {
        $this->checkUpdate($codeId, $label, $expiresAt, $maxUses);
        $written = $this->writeColumns(
            $codeId,
            ['label' => (string) $label, 'expires_at' => $expiresAt === null ? null : self::storeTime($expiresAt), 'max_uses' => $maxUses],
            fn (DynamicRecord $fresh): bool => $this->updateRefusal($fresh, $expiresAt, $maxUses) === null,
            self::liveGuard($maxUses),
        );
        if (!$written) {
            throw new CodeRefused(CodeRefused::CHANGED, 'The code changed meanwhile; reload it and try again.');
        }
    }

    /**
     * What update() would refuse, without writing: CodeRefused for an unknown code, a revoked or
     * used-up one, an expiry in the past (one the code already has is kept), max uses below the uses
     * so far, and max uses other than 1 on a code bound to a recipient.
     */
    public function checkUpdate(string $codeId, ?string $label, ?\DateTimeImmutable $expiresAt, ?int $maxUses): void
    {
        $record = $this->dm->findType('code', $codeId)
            ?? throw new CodeRefused(CodeRefused::UNKNOWN_CODE, "Unknown code [{$codeId}].");
        $refusal = $this->updateRefusal($record, $expiresAt, $maxUses);
        if ($refusal !== null) {
            throw $refusal;
        }
    }

    private function updateRefusal(DynamicRecord $record, ?\DateTimeImmutable $expiresAt, ?int $maxUses): ?CodeRefused
    {
        if ((string) ($record->get('revoked_at') ?? '') !== '') {
            return new CodeRefused(CodeRefused::REVOKED, 'A revoked code cannot be changed.');
        }
        $max = $record->get('max_uses');
        $uses = (int) $record->get('uses');
        if ($max !== null && $max !== '' && $uses >= (int) $max) {
            return new CodeRefused(CodeRefused::USED_UP, 'A used-up code cannot be changed.');
        }
        if ($expiresAt !== null && $expiresAt <= ($this->clock)() && self::storeTime($expiresAt) !== (string) ($record->get('expires_at') ?? '')) {
            return new CodeRefused(CodeRefused::EXPIRY_PAST, 'A code can only expire at a time in the future.');
        }
        if ($maxUses !== null && $maxUses < $uses) {
            return new CodeRefused(CodeRefused::MAX_USES_LOW, "Max uses cannot be below the {$uses} uses so far.");
        }
        if ((string) ($record->get('recipient') ?? '') !== '' && $maxUses !== 1) {
            return new CodeRefused(CodeRefused::BOUND_SINGLE_USE, 'A code bound to a recipient has exactly one use.');
        }

        return null;
    }

    /** E-mail addresses match without regard to case and surrounding spaces. */
    private static function normalizeRecipient(string $email): string
    {
        return strtolower(trim($email));
    }

    private static function storeTime(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d\TH:i');
    }

    private function toRecord(DynamicRecord $r): CodeRecord
    {
        $payload = json_decode((string) ($r->get('payload') ?? ''), true);
        $recipient = (string) ($r->get('recipient') ?? '');
        $max = $r->get('max_uses');

        return new CodeRecord(
            (string) $r->getId(),
            (string) $r->get('purpose'),
            (string) ($r->get('code') ?? ''),
            $recipient === '' ? null : $recipient,
            is_array($payload) ? $payload : [],
            self::parseTime($r->get('expires_at')),
            (int) $r->get('uses'),
            $max === null || $max === '' ? null : (int) $max,
            self::parseTime($r->get('revoked_at')),
            (string) ($r->get('form') ?? ''),
            (string) ($r->get('label') ?? ''),
        );
    }

    /** An unreadable time becomes the epoch: an expiry that has passed, a revocation that happened. */
    private static function parseTime(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable((string) $value);
        } catch (\Exception) {
            return new \DateTimeImmutable('@0');
        }
    }

    /**
     * Readable codes: upper case, with whitespace, Unicode separators and dashes (en dash,
     * non-breaking hyphen, minus sign, …), soft hyphens and zero-width characters removed; null
     * when the input is not valid UTF-8.
     */
    public static function normalizeReadable(string $code): ?string
    {
        $stripped = preg_replace('/[\s\p{Z}\p{Pd}\x{2212}\x{00AD}\x{200B}-\x{200D}\x{2060}\x{FEFF}]+/u', '', $code);

        return $stripped === null ? null : strtoupper($stripped);
    }

    /**
     * The purpose's code matching the typed one, normalised as its form says. An empty code
     * (typed or stored) never matches: it would be a bearer token without a secret.
     */
    private function find(string $code, string $purpose): ?DynamicRecord
    {
        $readable = self::normalizeReadable($code);
        $random = trim($code);
        foreach ($this->dm->queryType('code')->where('purpose', '=', $purpose)->get()->items() as $record) {
            $stored = (string) ($record->get('code') ?? '');
            $typed = $record->get('form') === 'readable' ? $readable : $random;
            if ($stored === '' || $typed === null || $typed === '') {
                continue;
            }
            if (hash_equals($stored, $typed)) {
                return $record;
            }
        }

        return null;
    }

    /** Whether an expires_at value (empty: never) has passed; an unreadable one counts as passed. */
    public function expired(mixed $expiresAt): bool
    {
        if ($expiresAt === null || $expiresAt === '') {
            return false;
        }
        try {
            $at = new \DateTimeImmutable((string) $expiresAt);
        } catch (\Exception) {
            return true; // an unreadable expiry grants nothing
        }

        return ($this->clock)() >= $at;
    }

    private function redemptionType(): TypeDefinition
    {
        return $this->redemptions ??= new TypeDefinition(
            key: '_code_redemption',
            table: self::REDEMPTIONS,
            // Beside the code type: its storage is "codes" or, without that alias, "default".
            storage: $this->dm->getTypeRegistry()?->get('code')?->storage ?? self::STORAGE,
            fields: [
                'code_id' => new TypeFieldDefinition('code_id', 'string'),
                'purpose' => new TypeFieldDefinition('purpose', 'string'),
                'redeemer' => new TypeFieldDefinition('redeemer', 'string'),
                'redeemed_at' => new TypeFieldDefinition('redeemed_at', 'number'),
            ],
            idField: 'id',
        );
    }
}
