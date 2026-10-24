<?php

declare(strict_types=1);

namespace Preflow\Rbac\Types;

use Preflow\Auth\UserProviderInterface;
use Preflow\Core\Container\Container;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Extension\Customization\Refused;
use Preflow\Rbac\ConfigUsers;
use Preflow\Rbac\Messages;
use Preflow\Rbac\PermissionCoverage;
use Preflow\Rbac\RoleStore;
use Preflow\Rbac\StaffManagers;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidationResult;

/**
 * Staff accounts (models/staff.json, the opt-in part "model:staff"). Every save: the e-mail
 * stored lowercased and unique (and, with a signed-in user, a new or changed one not a config
 * user's of the default guard); a new account starts active, with epoch "0" and created_at.
 * Delegation (PermissionCoverage), with a signed-in user: an account whose stored roles they
 * don't fully cover is not theirs to change or delete (viewing stays a matter of
 * permissions), and each role added or removed must be covered. Always (CLI too): a role is
 * added only by the name of a defined staff role, and the lock-out:
 * no save or delete may take the last active manager (StaffManagers) away. A crafted POST
 * gets the same answers as the form; the roles field only disables what is refused here.
 * Every write path runs it: the form's save and delete (StaffAdmin's hooks), the record
 * actions (StaffAdmin, on the changed record) and the import command.
 */
final class StaffRules
{
    public function __construct(
        private readonly DataManager $dm,
        private readonly PermissionCoverage $coverage,
        private readonly Messages $messages,
        private readonly Container $container,
    ) {}

    public function beforeSave(DynamicRecord $record): void
    {
        $id = (string) $record->getId();
        $stored = $id !== '' ? $this->dm->findType(StaffManagers::TYPE, $id) : null;

        $email = mb_strtolower(trim((string) $record->get('email')));
        $record->set('email', $email);
        if ($email !== '' && $this->emailTaken($email, $id)) {
            throw self::invalid('email', 'Another account already uses this email address.');
        }
        // In the admin, a new or changed address may not be a config user's: the record would then
        // decide that sign-in (a disabled one locks the config login out). The import (no
        // signed-in user) moves exactly those addresses.
        if ($email !== '' && $email !== ($stored === null ? null : mb_strtolower(trim((string) $stored->get('email'))))
            && $this->coverage->held() !== null && $this->configEmail($email)) {
            throw self::invalid('email', $this->messages->get('staff.config_email'));
        }
        if ($stored === null) {
            // A new account can sign in (the field default; code may leave it out), from epoch "0".
            if ($record->get('active') === null) {
                $record->set('active', true);
            }
            if (in_array($record->get('auth_epoch'), [null, ''], true)) {
                $record->set('auth_epoch', '0');
            }
            if (in_array($record->get('created_at'), [null, ''], true)) {
                $record->set('created_at', (new \DateTimeImmutable())->format(DATE_ATOM));
            }
        }

        $roles = (new RoleStore($this->dm))->rolesFor('staff');
        $before = $stored !== null ? StaffManagers::account($stored) : ['active' => false, 'roles' => []];
        $after = StaffManagers::account($record);
        // A role is added only by a name a staff role has now: an undefined name would grant
        // whatever a role created under it later gets, past every delegation check. Names
        // already stored may stay.
        $unknown = array_values(array_filter(array_diff($after['roles'], $before['roles']), static fn (string $name): bool => !isset($roles[$name])));
        if ($unknown !== []) {
            throw self::invalid('roles', $this->messages->get('delegation.unknown', ['names' => implode(', ', $unknown)]));
        }
        if (($message = $this->delegation($before['roles'], $after['roles'], $roles)) !== null) {
            throw self::invalid('roles', $message);
        }

        $accounts = (new StaffManagers($this->dm))->accounts();
        $before = $accounts[$id] ?? $before;
        $managers = StaffManagers::count($accounts, $roles);
        $accounts[$id] = $after;
        if ($managers > 0 && StaffManagers::count($accounts, $roles) === 0) {
            throw self::invalid($before['active'] && !$after['active'] ? 'active' : 'roles', $this->messages->get('delegation.last'));
        }
    }

    public function beforeDelete(DynamicRecord $record): void
    {
        $id = (string) $record->getId();
        $roles = (new RoleStore($this->dm))->rolesFor('staff');
        $accounts = (new StaffManagers($this->dm))->accounts();
        $stored = $accounts[$id] ?? StaffManagers::account($record);
        $held = $this->coverage->held();
        if ($held !== null && !PermissionCoverage::coversAll($held, StaffManagers::patterns($stored['roles'], $roles))) {
            throw new Refused($this->messages->get('delegation.account'));
        }

        $managers = StaffManagers::count($accounts, $roles);
        unset($accounts[$id]);
        if ($managers > 0 && StaffManagers::count($accounts, $roles) === 0) {
            throw new Refused($this->messages->get('delegation.last'));
        }
    }

    /**
     * The refusal when the signed-in user doesn't cover the account's stored roles, or a role
     * added or removed; null without a signed-in user (CLI, install, import).
     *
     * @param list<string> $before the stored role names ([] for a new account)
     * @param list<string> $after
     * @param array<string, list<string>> $roles staff role name => patterns
     */
    private function delegation(array $before, array $after, array $roles): ?string
    {
        $held = $this->coverage->held();
        if ($held === null) {
            return null;
        }
        if (!PermissionCoverage::coversAll($held, StaffManagers::patterns($before, $roles))) {
            return $this->messages->get('delegation.account');
        }
        $changed = [...array_diff($after, $before), ...array_diff($before, $after)];
        $uncovered = PermissionCoverage::uncovered($held, StaffManagers::patterns(array_values($changed), $roles));

        return $uncovered === [] ? null : $this->messages->get('delegation.grant', ['patterns' => implode(', ', $uncovered)]);
    }

    /** Whether a config user of the default guard has this e-mail. */
    private function configEmail(string $email): bool
    {
        try {
            $users = $this->container->get(UserProviderInterface::class);
        } catch (\Throwable) { // no config/auth.php: nothing bound
            return false;
        }

        return ConfigUsers::hasEmail($users, $email);
    }

    private function emailTaken(string $email, string $exceptId): bool
    {
        foreach ($this->dm->queryType(StaffManagers::TYPE)->where('email', $email)->get()->items() as $other) {
            if ((string) $other->getId() !== $exceptId) {
                return true;
            }
        }

        return false;
    }

    private static function invalid(string $field, string $message): ValidationException
    {
        return new ValidationException(new ValidationResult([$field => [$message]]));
    }
}
