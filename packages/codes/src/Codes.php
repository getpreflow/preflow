<?php

declare(strict_types=1);

namespace Preflow\Codes;

use Preflow\Core\Throttle\Throttle;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\MysqlDialect;
use Preflow\Data\Driver\PdoDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\SortDirection;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;

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

    /** @var array<string, Purpose> */
    private readonly array $purposes;
    private readonly int $throttleMax;
    private readonly int $throttleWindow;
    private readonly \Closure $clock;
    private readonly string $lockDir;
    private ?TypeDefinition $redemptions = null;

    /**
     * @param array<string, mixed> $config config/codes.php: purposes (name => label, readable) and throttle (max, window)
     * @param (\Closure(): \DateTimeImmutable)|null $clock
     */
    public function __construct(
        private readonly DataManager $dm,
        private readonly Throttle $throttle,
        array $config = [],
        ?\Closure $clock = null,
        ?string $lockDir = null,
        private readonly ?CodeEvents $events = null,
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
    }

    /** @return array<string, Purpose> by name */
    public function purposes(): array
    {
        return $this->purposes;
    }

    public function purpose(string $name): Purpose
    {
        return $this->purposes[$name]
            ?? throw new \InvalidArgumentException("Unknown code purpose [{$name}]; add it to config/codes.php 'purposes'.");
    }

    /**
     * Issues a code and returns it as stored: a readable one when $code is given (normalised,
     * 4–64 characters A–Z and 0–9, only for purposes that allow readable codes), a random one
     * otherwise. Codes are unique within their purpose. Without $expiresAt a code lives as long
     * as the purpose's ttl (days) says, if it has one.
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
        $definition = $this->purpose($purpose);
        if ($recipient !== null) {
            if ($code !== null) {
                throw new \InvalidArgumentException('A readable code cannot be bound to a recipient.');
            }
            $recipient = self::normalizeRecipient($recipient);
            if (!str_contains($recipient, '@')) {
                throw new \InvalidArgumentException('A recipient needs an e-mail address.');
            }
            $maxUses = 1;
        }
        $now = ($this->clock)();
        if ($expiresAt === null && $definition->ttl !== null) {
            $expiresAt = $now->modify("+{$definition->ttl} days");
        }
        if ($code !== null) {
            if (!$definition->readable) {
                throw new \InvalidArgumentException("Code purpose [{$purpose}] does not allow readable codes.");
            }
            $code = self::normalizeReadable($code);
            if ($code === null || preg_match('/^[A-Z0-9]{4,64}$/', $code) !== 1) {
                throw new \InvalidArgumentException('A readable code needs 4 to 64 letters A-Z and digits (spaces and dashes are ignored).');
            }
            $form = 'readable';
        } else {
            $code = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
            $form = 'random';
        }
        if ($this->find($code, $purpose) !== null) {
            throw new \InvalidArgumentException("A code like this already exists for purpose [{$purpose}].");
        }

        $type = $this->dm->getTypeRegistry()?->get('code')
            ?? throw new \RuntimeException('preflow/codes: the type "code" is not defined (no config/data.php?).');
        $this->dm->saveType(DynamicRecord::fromArray($type, [
            $type->idField => bin2hex(random_bytes(16)),
            'purpose' => $purpose,
            'code' => $code,
            'label' => $label,
            'expires_at' => $expiresAt === null ? null : self::storeTime($expiresAt),
            'max_uses' => $maxUses,
            'uses' => 0,
            'revoked_at' => null,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'form' => $form,
            'recipient' => $recipient,
            'created_at' => $now->getTimestamp(),
        ]), false);

        return $code;
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

        $redemptionId = bin2hex(random_bytes(16));
        $this->dm->saveType(new DynamicRecord($this->redemptionType(), [
            'id' => $redemptionId,
            'code_id' => $result->codeId,
            'purpose' => $purpose,
            'redeemer' => $redeemer,
            'redeemed_at' => ($this->clock)()->getTimestamp(),
        ]), false);

        if ($this->events !== null) {
            try {
                $this->dispatch(CodeEvents::REDEEMED, (string) $result->codeId, $redeemer);
            } catch (\Throwable $e) {
                // The redeem did not happen: give the use back and drop this redemption, no throttle hit.
                $this->uncountUse((string) $result->codeId);
                $this->dm->deleteIn($this->redemptionType(), $redemptionId);

                throw $e;
            }
        }

        return $result;
    }

    private function dispatch(string $event, string $codeId, ?string $redeemer): void
    {
        $code = $this->get($codeId);
        if ($code === null) {
            return;
        }
        $this->events?->dispatch($event, [
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
            [$table, $id, $uses, $max, $count, $limit] = $this->sql($driver, $type);

            return $this->dm->execute(
                "UPDATE {$table} SET {$uses} = {$count} + 1 WHERE {$id} = ? AND ({$max} IS NULL OR {$max} = '' OR {$count} < {$limit})",
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
            [$table, $id, $uses, , $count] = $this->sql($driver, $type);
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
     * Quoted names and the numeric expressions for the use counting UPDATEs.
     *
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string} table, id, uses, max, count, limit
     */
    private function sql(PdoDriver $driver, TypeDefinition $type): array
    {
        $dialect = $driver->dialect();
        $table = $dialect->quoteIdentifier($type->table);
        $id = $dialect->quoteIdentifier($type->idField);
        $uses = $dialect->quoteIdentifier('uses');
        $max = $dialect->quoteIdentifier('max_uses');
        // Folio syncs these columns as TEXT, so compare as numbers, never as strings.
        if ($dialect instanceof MysqlDialect) {
            // Strict mode turns CAST('' AS SIGNED) and CAST('3.0' AS SIGNED) into errors
            // inside an UPDATE, so only well-formed numbers are cast; '' uses count as 0.
            $count = "(CASE WHEN {$uses} REGEXP '^[0-9]+$' THEN CAST({$uses} AS SIGNED) ELSE 0 END)";
            $limit = "(CASE WHEN {$max} REGEXP '^[0-9]+([.][0-9]*)?$' THEN CAST({$max} AS DECIMAL(65,10)) ELSE 0 END)";
        } else {
            $count = "CAST(COALESCE({$uses}, 0) AS INTEGER)";
            $limit = "CAST({$max} AS INTEGER)";
        }

        return [$table, $id, $uses, $max, $count, $limit];
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

    /** Revokes the code; a revoked code keeps its first revocation time. */
    public function revoke(string $codeId): void
    {
        $record = $this->dm->findType('code', $codeId)
            ?? throw new \InvalidArgumentException("Unknown code [{$codeId}].");
        if ((string) ($record->get('revoked_at') ?? '') !== '') {
            return;
        }
        $record->set('revoked_at', ($this->clock)()->format(DATE_ATOM));
        $this->dm->saveType($record, false);
        $this->dispatch(CodeEvents::REVOKED, $codeId, null);
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
        $record->set('expires_at', self::storeTime($expiresAt));
        $this->dm->saveType($record, false);
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
