<?php

declare(strict_types=1);

namespace Preflow\Auth\Record;

use Preflow\Auth\Authenticatable;
use Preflow\Auth\ClaimsCredentials;
use Preflow\Auth\UpdatesPasswordHash;
use Preflow\Auth\UserProviderInterface;
use Preflow\Auth\UserProviderUnavailable;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeDefinition;

/**
 * Users stored as records of a data type (Folio's user types, the members type), for a SessionGuard.
 * Config: ['class' => self::class, 'type' => 'member', 'email' => 'email',
 * 'password' => 'password_hash', 'roles' => 'roles', 'epoch' => 'auth_epoch'] (the field names shown
 * are the defaults). The epoch field ends a user's other sessions when it changes (RecordUser); a
 * type without it never does. Optional 'active' => '<field>': a record whose field is false, 0, '0',
 * '' or empty never resolves, by id or credentials (so a signed-in user is out on the next request).
 * Without the key every record resolves.
 *
 * The type's definition comes from $types (the type key in, its definition or null out): Folio
 * passes its type catalog's lookup, which also refuses a type that is not a user type. Without
 * one, the DataManager's type registry (types defined in code included). An unknown type makes
 * the provider unavailable (UserProviderUnavailable).
 */
final class RecordUserProvider implements UserProviderInterface, ClaimsCredentials, UpdatesPasswordHash
{
    private readonly string $type;
    private readonly TypeDefinition $definition;
    private readonly string $emailField;
    private readonly string $passwordField;
    private readonly string $rolesField;
    private readonly string $epochField;
    private readonly ?string $activeField;

    /**
     * @param array<string, mixed>                       $config
     * @param ?\Closure(string): ?TypeDefinition         $types  the type's definition by key; null: the DataManager's registry
     */
    public function __construct(
        private readonly DataManager $dm,
        array $config,
        ?\Closure $types = null,
    ) {
        $this->type = (string) ($config['type'] ?? '');
        $types ??= static function (string $type) use ($dm): ?TypeDefinition {
            $registry = $dm->getTypeRegistry();

            return $registry !== null && $type !== '' && $registry->has($type) ? $registry->get($type) : null;
        };
        $this->definition = $types($this->type)
            ?? throw new UserProviderUnavailable("User provider: type \"{$this->type}\" does not exist.");
        $this->emailField = (string) ($config['email'] ?? 'email');
        $this->passwordField = (string) ($config['password'] ?? 'password_hash');
        $this->rolesField = (string) ($config['roles'] ?? 'roles');
        $this->epochField = (string) ($config['epoch'] ?? 'auth_epoch');
        $active = $config['active'] ?? null;
        $this->activeField = is_string($active) && $active !== '' ? $active : null;
    }

    /** The user type's key. */
    public function type(): string
    {
        return $this->type;
    }

    /** @return array{email: string, password: string, roles: string, epoch: string} the field names of the record's parts */
    public function fields(): array
    {
        return ['email' => $this->emailField, 'password' => $this->passwordField, 'roles' => $this->rolesField, 'epoch' => $this->epochField];
    }

    public function findById(string $id): ?Authenticatable
    {
        $record = $this->dm->findIn($this->definition, $id);

        return $record === null || !$this->isActive($record) ? null : $this->user($record);
    }

    public function findByCredentials(array $credentials): ?Authenticatable
    {
        $exact = $this->exact($credentials);

        return count($exact) === 1 && $this->isActive($exact[0]) ? $this->user($exact[0]) : null;
    }

    /** True when a record has this e-mail, active or not: a chain then leaves the decision to this provider. */
    public function claims(array $credentials): bool
    {
        return $this->exact($credentials) !== [];
    }

    /**
     * Stores $newHash on the user's record as stored now, only when its hash is still $oldHash (a
     * password changed meanwhile stays). Nothing else changes: the epoch stays, so sessions do too.
     * False for a user that is not one of this type's records, a record gone or a hash changed.
     */
    public function updatePasswordHash(Authenticatable $user, string $oldHash, string $newHash): bool
    {
        if (!$user instanceof RecordUser || $user->record()->getType()->key !== $this->type) {
            return false;
        }
        $fresh = $this->dm->findIn($this->definition, $user->getAuthId());
        if ($fresh === null || $fresh->get($this->passwordField) !== $oldHash) {
            return false;
        }
        $fresh->set($this->passwordField, $newHash);
        $this->dm->saveType($fresh, false);

        return true;
    }

    /** @return list<DynamicRecord> records whose e-mail is exactly the (lowercased) one given */
    private function exact(array $credentials): array
    {
        $email = $credentials['email'] ?? null;
        if (!is_string($email) || trim($email) === '') {
            return [];
        }
        $email = mb_strtolower(trim($email));
        // The database may compare loosely (MySQL collations ignore case and accents): only the exact
        // stored value counts, and a look-alike or duplicate never picks an account.
        $exact = [];
        foreach ($this->dm->queryIn($this->definition)->where($this->emailField, $email)->get() as $record) {
            if ($record->get($this->emailField) === $email) {
                $exact[] = $record;
            }
        }

        return $exact;
    }

    private function isActive(DynamicRecord $record): bool
    {
        if ($this->activeField === null) {
            return true;
        }
        $value = $record->get($this->activeField);

        return !in_array($value, [null, false, 0, '0', '', 'false'], true);
    }

    private function user(DynamicRecord $record): RecordUser
    {
        return new RecordUser($record, $this->passwordField, $this->rolesField, $this->epochField, $this->emailField);
    }
}
