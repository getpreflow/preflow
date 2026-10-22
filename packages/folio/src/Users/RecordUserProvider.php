<?php

declare(strict_types=1);

namespace Preflow\Folio\Users;

use Preflow\Auth\Authenticatable;
use Preflow\Auth\UserProviderInterface;
use Preflow\Data\DataManager;
use Preflow\Folio\Content\TypeCatalog;

/**
 * Users from a Folio user type (a type with "users": "<population>"), for a SessionGuard.
 * Config: ['class' => self::class, 'type' => 'member', 'email' => 'email',
 * 'password' => 'password_hash', 'roles' => 'roles'] (the field names shown are the defaults).
 */
final class RecordUserProvider implements UserProviderInterface
{
    private readonly string $type;
    private readonly string $emailField;
    private readonly string $passwordField;
    private readonly string $rolesField;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly DataManager $dm,
        TypeCatalog $catalog,
        array $config,
    ) {
        $this->type = (string) ($config['type'] ?? '');
        $listing = $catalog->get($this->type);
        if ($listing === null) {
            throw new \RuntimeException("User provider: type \"{$this->type}\" does not exist.");
        }
        if ($listing->users === null) {
            throw new \RuntimeException("User provider: type \"{$this->type}\" is not a user type (set \"users\" in its model).");
        }
        $this->emailField = (string) ($config['email'] ?? 'email');
        $this->passwordField = (string) ($config['password'] ?? 'password_hash');
        $this->rolesField = (string) ($config['roles'] ?? 'roles');
    }

    public function findById(string $id): ?Authenticatable
    {
        $record = $this->dm->findType($this->type, $id);

        return $record === null ? null : $this->user($record);
    }

    public function findByCredentials(array $credentials): ?Authenticatable
    {
        $email = $credentials['email'] ?? null;
        if (!is_string($email) || trim($email) === '') {
            return null;
        }
        $record = $this->dm->queryType($this->type)
            ->where($this->emailField, mb_strtolower(trim($email)))
            ->first();

        return $record === null ? null : $this->user($record);
    }

    private function user(\Preflow\Data\DynamicRecord $record): RecordUser
    {
        return new RecordUser($record, $this->passwordField, $this->rolesField);
    }
}
