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
use Preflow\Rbac\Messages;
use Preflow\Rbac\PermissionCoverage;
use Preflow\Rbac\RoleList;
use Preflow\Rbac\StaffManagers;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidationResult;

/**
 * Role names are unique per population. Delegation (PermissionCoverage): the signed-in
 * user saves or deletes only a role whose stored patterns they cover (no touching a role
 * above them), and adds only patterns they cover; the uncovered ones are named in the
 * error. Without a signed-in user (CLI) delegation does not apply. Nobody locks roles
 * editing away either: a save or delete is refused when, on the roles as they would be
 * afterwards, the signed-in user's own staff roles together no longer grant both
 * rbac_role.view (the list and edit form) and rbac_role.edit, or the staff roles together
 * grant them no more. A changed name or population counts as losing the role. With staff
 * accounts (the staff type), a role save or delete may not leave no active manager either
 * (StaffManagers: staff.edit and rbac_role.edit).
 */
#[FolioType('rbac_role')]
final class RoleRules
{
    /** Seeing the roles list and edit form, and saving them. */
    private const array NEEDED = ['rbac_role.view', 'rbac_role.edit'];
    private const string OWN = 'You would lose the right to edit roles.';
    private const string LAST = 'No staff role would be left that may edit roles.';

    public function __construct(
        private readonly DataManager $dm,
        private readonly AuthManager $auth,
        private readonly PermissionCoverage $coverage,
        private readonly Messages $messages,
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

        if (($message = $this->delegation($roles[$id]['permissions'] ?? [], $role['permissions'])) !== null) {
            throw new ValidationException(new ValidationResult(['permissions' => [$message]]));
        }

        $before = $roles;
        $roles[$id] = $role;
        if (($message = $this->lockout($roles) ?? $this->managers($before, $roles)) !== null) {
            throw new ValidationException(new ValidationResult(['permissions' => [$message]]));
        }
    }

    #[BeforeDelete]
    public function beforeDelete(DynamicRecord $record): void
    {
        $roles = $this->roles();
        $id = (string) $record->getId();
        if (($message = $this->delegation($roles[$id]['permissions'] ?? self::role($record)['permissions'], [])) !== null) {
            throw new Refused($message);
        }
        $before = $roles;
        unset($roles[$id]);
        if (($message = $this->lockout($roles) ?? $this->managers($before, $roles)) !== null) {
            throw new Refused($message);
        }
    }

    /**
     * The refusal when the staff accounts had an active manager with the roles before and none after.
     *
     * @param array<string, array{name: string, population: string, permissions: list<string>}> $before
     * @param array<string, array{name: string, population: string, permissions: list<string>}> $after
     */
    private function managers(array $before, array $after): ?string
    {
        $accounts = (new StaffManagers($this->dm))->accounts();
        if ($accounts === []) {
            return null;
        }

        return StaffManagers::count($accounts, self::staffRoles($before)) > 0 && StaffManagers::count($accounts, self::staffRoles($after)) === 0
            ? $this->messages->get('delegation.last')
            : null;
    }

    /**
     * @param array<string, array{name: string, population: string, permissions: list<string>}> $roles
     * @return array<string, list<string>> staff role name => patterns
     */
    private static function staffRoles(array $roles): array
    {
        $staff = [];
        foreach ($roles as $role) {
            if ($role['population'] === 'staff' && $role['name'] !== '') {
                $staff[$role['name']] = [...($staff[$role['name']] ?? []), ...$role['permissions']];
            }
        }

        return $staff;
    }

    /**
     * The refusal when the signed-in user doesn't cover the role as stored, or a pattern added to it.
     *
     * @param list<string> $before the stored patterns ([] for a new role)
     * @param list<string> $after the patterns afterwards ([] on delete)
     */
    private function delegation(array $before, array $after): ?string
    {
        $held = $this->coverage->held();
        if ($held === null) {
            return null;
        }
        $uncovered = PermissionCoverage::uncovered($held, [...$before, ...array_diff($after, $before)]);

        return $uncovered === [] ? null : $this->messages->get('delegation.grant', ['patterns' => implode(', ', $uncovered)]);
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
        $missing = self::NEEDED;
        foreach ($roles as $role) {
            if ($role['population'] !== 'staff' || ($names !== null && !in_array($role['name'], $names, true))) {
                continue;
            }
            foreach ($role['permissions'] as $pattern) {
                $missing = array_filter($missing, static fn (string $needed): bool => !Pattern::matches($pattern, $needed));
            }
        }

        return $missing === [];
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
