<?php

declare(strict_types=1);

namespace Preflow\Folio\Users;

use Preflow\Auth\Authenticatable;
use Preflow\Auth\UserProviderInterface;
use Preflow\Auth\UserProviderUnavailable;
use Preflow\Data\DataManager;
use Preflow\Folio\Content\TypeCatalog;

/**
 * Users from a Folio user type (a type with "users": "<population>"), for a SessionGuard.
 * Config: ['class' => self::class, 'type' => 'member', 'email' => 'email',
 * 'password' => 'password_hash', 'roles' => 'roles', 'epoch' => 'auth_epoch'] (the field names shown
 * are the defaults). The epoch field ends a user's other sessions when it changes (RecordUser); a
 * type without it never does. Optional 'active' => '<field>': a record whose field is false, 0, '0',
 * '' or empty never resolves, by id or credentials (so a signed-in user is out on the next request).
 * Without the key every record resolves.
 */
final class RecordUserProvider implements UserProviderInterface
{
    private readonly string $type;
    private readonly string $emailField;
    private readonly string $passwordField;
    private readonly string $rolesField;
    private readonly string $epochField;
    private readonly ?string $activeField;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly DataManager $dm,
        TypeCatalog $catalog,
        array $config,
    ) {
        $this->type = (string) ($config['type'] ?? '');
        $listing = $catalog->get($this->type);
        if ($listing === null) {
            throw new UserProviderUnavailable("User provider: type \"{$this->type}\" does not exist.");
        }
        if ($listing->users === null) {
            throw new \RuntimeException("User provider: type \"{$this->type}\" is not a user type (set \"users\" in its model).");
        }
        $this->emailField = (string) ($config['email'] ?? 'email');
        $this->passwordField = (string) ($config['password'] ?? 'password_hash');
        $this->rolesField = (string) ($config['roles'] ?? 'roles');
        $this->epochField = (string) ($config['epoch'] ?? 'auth_epoch');
        $active = $config['active'] ?? null;
        $this->activeField = is_string($active) && $active !== '' ? $active : null;
    }

    public function findById(string $id): ?Authenticatable
    {
        $record = $this->dm->findType($this->type, $id);

        return $record === null || !$this->isActive($record) ? null : $this->user($record);
    }

    public function findByCredentials(array $credentials): ?Authenticatable
    {
        $email = $credentials['email'] ?? null;
        if (!is_string($email) || trim($email) === '') {
            return null;
        }
        $email = mb_strtolower(trim($email));
        // The database may compare loosely (MySQL collations ignore case and accents): only the exact
        // stored value counts, and a look-alike or duplicate never picks an account.
        $exact = [];
        foreach ($this->dm->queryType($this->type)->where($this->emailField, $email)->get() as $record) {
            if ($record->get($this->emailField) === $email && $this->isActive($record)) {
                $exact[] = $record;
            }
        }

        return count($exact) === 1 ? $this->user($exact[0]) : null;
    }

    private function isActive(\Preflow\Data\DynamicRecord $record): bool
    {
        if ($this->activeField === null) {
            return true;
        }
        $value = $record->get($this->activeField);

        return !in_array($value, [null, false, 0, '0', '', 'false'], true);
    }

    private function user(\Preflow\Data\DynamicRecord $record): RecordUser
    {
        return new RecordUser($record, $this->passwordField, $this->rolesField, $this->epochField);
    }
}
