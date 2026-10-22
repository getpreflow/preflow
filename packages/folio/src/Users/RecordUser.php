<?php

declare(strict_types=1);

namespace Preflow\Folio\Users;

use Preflow\Auth\Authenticatable;
use Preflow\Data\DynamicRecord;

/** A record of a Folio user type as the logged-in account. */
final class RecordUser implements Authenticatable
{
    /** @var list<string> */
    private readonly array $roles;

    public function __construct(
        private readonly DynamicRecord $record,
        private readonly string $passwordField = 'password_hash',
        string $rolesField = 'roles',
    ) {
        $this->roles = self::roles($record->get($rolesField));
    }

    public function getAuthId(): string
    {
        return (string) $this->record->getId();
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

    public function record(): DynamicRecord
    {
        return $this->record;
    }

    /**
     * An array, a JSON array string (as SQL returns a stored list) or a comma-separated string.
     * Kept local: Folio does not depend on preflow/rbac.
     *
     * @return list<string> trimmed, unique, non-empty
     */
    private static function roles(mixed $value): array
    {
        if (is_string($value)) {
            $value = trim($value);
            if (str_starts_with($value, '[')) {
                $decoded = json_decode($value, true);
                $value = is_array($decoded) ? $decoded : [];
            } else {
                $value = explode(',', $value);
            }
        }
        $out = [];
        foreach (is_array($value) ? $value : [] as $role) {
            if (!is_string($role) && !is_int($role)) {
                continue;
            }
            $role = trim((string) $role);
            if ($role !== '' && !in_array($role, $out, true)) {
                $out[] = $role;
            }
        }

        return $out;
    }
}
