<?php

declare(strict_types=1);

namespace Preflow\Rbac\Types;

use Preflow\Auth\Access\Pattern;
use Preflow\Auth\AuthManager;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Extension\Attributes\FolioType;
use Preflow\Folio\Extension\BeforeDelete;
use Preflow\Folio\Extension\BeforeSave;
use Preflow\Folio\Extension\Customization\Refused;
use Preflow\Rbac\RoleList;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidationResult;

/**
 * Role names are unique per population, and nobody locks roles editing away: a save or
 * delete is refused when, on the roles as they would be afterwards, the signed-in user's
 * own roles no longer grant rbac_role.edit, or no staff role grants it at all. A changed
 * name or population counts as losing the role.
 */
#[FolioType('rbac_role')]
final class RoleRules
{
    private const string EDIT = 'rbac_role.edit';
    private const string OWN = 'You would lose the right to edit roles.';
    private const string LAST = 'No staff role would be left that may edit roles.';

    public function __construct(
        private readonly DataManager $dm,
        private readonly AuthManager $auth,
    ) {}

    #[BeforeSave]
    public function beforeSave(DynamicRecord $record): void
    {
        $id = (string) $record->getId();
        $role = self::role($record);
        $roles = $this->roles();
        foreach ($roles as $otherId => $other) {
            if ($otherId !== $id && $other['name'] === $role['name'] && $other['population'] === $role['population']) {
                throw new ValidationException(new ValidationResult(['name' => ["A {$role['population']} role named {$role['name']} already exists."]]));
            }
        }

        $roles[$id] = $role;
        if (($message = $this->lockout($roles)) !== null) {
            throw new ValidationException(new ValidationResult(['permissions' => [$message]]));
        }
    }

    #[BeforeDelete]
    public function beforeDelete(DynamicRecord $record): void
    {
        $roles = $this->roles();
        unset($roles[(string) $record->getId()]);
        if (($message = $this->lockout($roles)) !== null) {
            throw new Refused($message);
        }
    }

    /** @param array<string, array{name: string, population: string, permissions: list<string>}> $roles the roles afterwards */
    private function lockout(array $roles): ?string
    {
        $user = $this->auth->user();
        if ($user !== null && !self::grants($roles, RoleList::normalize($user->getRoles()))) {
            return self::OWN;
        }

        return self::grants($roles, null) ? null : self::LAST;
    }

    /**
     * @param array<string, array{name: string, population: string, permissions: list<string>}> $roles
     * @param list<string>|null $names the user's roles; null: any staff role
     */
    private static function grants(array $roles, ?array $names): bool
    {
        foreach ($roles as $role) {
            if ($role['population'] !== 'staff' || ($names !== null && !in_array($role['name'], $names, true))) {
                continue;
            }
            foreach ($role['permissions'] as $pattern) {
                if (Pattern::matches($pattern, self::EDIT)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array<string, array{name: string, population: string, permissions: list<string>}> by id, as stored now */
    private function roles(): array
    {
        $roles = [];
        foreach ($this->dm->queryType('rbac_role')->get()->items() as $record) {
            $roles[(string) $record->getId()] = self::role($record);
        }

        return $roles;
    }

    /** @return array{name: string, population: string, permissions: list<string>} */
    private static function role(DynamicRecord $record): array
    {
        return [
            'name' => trim((string) $record->get('name')),
            'population' => (string) $record->get('population'),
            'permissions' => RoleList::normalize($record->get('permissions')),
        ];
    }
}
