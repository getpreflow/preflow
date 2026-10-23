<?php

declare(strict_types=1);

namespace Preflow\Members;

use Preflow\Auth\PasswordHasherInterface;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;

/** Member records: registration, confirmation, the owner's decision, deletion. */
final class Members
{
    private const string TYPE = MembersConfig::TYPE;

    /** Fields register() fills itself; $extra must not set them. */
    private const array OWN_FIELDS = ['uuid', 'email', 'name', 'password_hash', 'language', 'roles', 'registered_at', 'email_confirmed_at', 'decided_at'];

    private readonly \Closure $now;

    /** @param ?\Closure(): \DateTimeImmutable $now UTC clock, injectable for tests */
    public function __construct(
        private readonly DataManager $dm,
        private readonly PasswordHasherInterface $hasher,
        ?\Closure $now = null,
    ) {
        $this->now = $now ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function findByEmail(string $email): ?DynamicRecord
    {
        $record = $this->dm->queryType(self::TYPE)->where('email', self::normalizeEmail($email))->first();

        return $record instanceof DynamicRecord ? $record : null;
    }

    /** Whether a member other than $exceptId has this address (compared normalised). */
    public function emailTaken(string $email, string $exceptId): bool
    {
        foreach ($this->dm->queryType(self::TYPE)->where('email', self::normalizeEmail($email))->get()->items() as $other) {
            if ((string) $other->getId() !== $exceptId) {
                return true;
            }
        }

        return false;
    }

    public function find(string $id): ?DynamicRecord
    {
        return $this->dm->findType(self::TYPE, $id);
    }

    /**
     * A new, unconfirmed member without roles. $extra (from Admission::recordValues()) sets
     * further fields of the member type; a key the type lacks is a programming error.
     *
     * @param array<string, mixed> $extra
     */
    public function register(string $email, string $name, string $password, string $language, array $extra = []): DynamicRecord
    {
        $def = ($this->dm->getTypeRegistry() ?? throw new \LogicException('No type registry.'))->get(self::TYPE);
        foreach (array_keys($extra) as $key) {
            if (in_array($key, self::OWN_FIELDS, true)) {
                throw new \LogicException("Members::register(): \"{$key}\" is set by Members itself, not by the admission's recordValues().");
            }
            if (!isset($def->fields[$key])) {
                throw new \LogicException("Members::register(): \"{$key}\" is not a field of the member type; add it with a site model file member.json that says \"extends\": true.");
            }
        }
        $record = DynamicRecord::fromArray($def, [
            'uuid' => self::uuid(),
            'email' => self::normalizeEmail($email),
            'name' => trim($name),
            'password_hash' => $this->hasher->hash($password),
            'language' => $language,
            'roles' => [],
            'registered_at' => $this->stamp(),
            'email_confirmed_at' => '',
            'decided_at' => '',
        ] + $extra);
        $this->dm->saveType($record, false);

        return $record;
    }

    public function confirm(DynamicRecord $m): void
    {
        $m->set('email_confirmed_at', $this->stamp());
        $this->save($m);
    }

    /** @param string $role member, pending or rejected */
    public function decide(DynamicRecord $m, string $role): void
    {
        $m->set('roles', [$role]);
        if ($role === 'member' || $role === 'rejected') {
            $m->set('decided_at', $this->stamp());
        }
        $this->save($m);
    }

    public function setPassword(DynamicRecord $m, string $password): void
    {
        $m->set('password_hash', $this->hasher->hash($password));
        $this->save($m);
    }

    public function delete(DynamicRecord $m): void
    {
        $this->dm->deleteType(self::TYPE, (string) $m->getId());
    }

    /**
     * Removes a member whose confirm mail could not be sent, read afresh: one who confirmed
     * meanwhile (with the link of an earlier mail) is kept. Returns whether it was removed.
     */
    public function removeIfUnconfirmed(string $id): bool
    {
        $m = $this->find($id);
        if ($m === null || MemberState::fromRecord($m) !== MemberState::Unconfirmed) {
            return false;
        }
        $this->delete($m);

        return true;
    }

    /** Confirmed accounts the owner has not decided yet. */
    public function pendingCount(): int
    {
        $count = 0;
        foreach ($this->dm->queryType(self::TYPE)->get() as $m) {
            if ($m instanceof DynamicRecord && MemberState::fromRecord($m) === MemberState::Waiting) {
                $count++;
            }
        }

        return $count;
    }

    private function save(DynamicRecord $m): void
    {
        $this->dm->saveType($m, false);
    }

    private function stamp(): string
    {
        return ($this->now)()->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM);
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
