<?php

declare(strict_types=1);

namespace Preflow\Auth\Record;

use Preflow\Auth\Authenticatable;
use Preflow\Auth\HasSessionEpoch;
use Preflow\Data\DynamicRecord;

/**
 * A record of a user type as the logged-in account. Its session epoch is the value of
 * $epochField ("0" when the type has no such field): bumping it ends the user's other sessions.
 */
final class RecordUser implements Authenticatable, HasSessionEpoch
{
    /** @var list<string> */
    private readonly array $roles;

    public function __construct(
        private readonly DynamicRecord $record,
        private readonly string $passwordField = 'password_hash',
        string $rolesField = 'roles',
        private readonly string $epochField = 'auth_epoch',
        private readonly string $emailField = 'email',
    ) {
        $this->roles = RoleField::normalize($record->get($rolesField));
    }

    public function getAuthId(): string
    {
        return (string) $this->record->getId();
    }

    /** The address the account signs in with (as a config user's getEmail()). */
    public function getEmail(): string
    {
        $email = $this->record->get($this->emailField);

        return is_scalar($email) ? (string) $email : '';
    }

    public function getPasswordHash(): string
    {
        return (string) $this->record->get($this->passwordField);
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return $this->roles;
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function getSessionEpoch(): string
    {
        $epoch = $this->record->get($this->epochField);

        return is_scalar($epoch) && (string) $epoch !== '' ? (string) $epoch : '0';
    }

    public function record(): DynamicRecord
    {
        return $this->record;
    }
}
