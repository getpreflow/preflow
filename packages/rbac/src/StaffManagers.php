<?php

declare(strict_types=1);

namespace Preflow\Rbac;

use Preflow\Auth\Access\Pattern;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\PdoDriver;
use Preflow\Data\DynamicRecord;

/**
 * Who can manage staff and roles: an active staff account (rbac's "staff" type) whose staff
 * roles together grant staff.edit and rbac_role.edit. The lock-out rule keeps at least one
 * (StaffRules for accounts, RoleRules for roles); a site without the staff type has none to keep.
 */
final class StaffManagers
{
    public const string TYPE = 'staff';

    /** What a manager's roles grant. */
    public const array NEEDED = ['staff.edit', 'rbac_role.edit'];

    public function __construct(private readonly DataManager $dm) {}

    /** Whether the site has the staff type (the opt-in part "model:staff" is on). */
    public function enabled(): bool
    {
        return $this->dm->getTypeRegistry()?->has(self::TYPE) ?? false;
    }

    /**
     * Whether the staff accounts can be read: the site has the staff type and, on SQL storage,
     * its table (made by `rbac:install`, or `migrate` where Folio's part defines the type);
     * JSON storage makes it on the first write.
     */
    public function hasTable(): bool
    {
        if (!$this->enabled()) {
            return false;
        }
        $definition = $this->dm->getTypeRegistry()->get(self::TYPE);

        return !$this->dm->driver($definition->storage) instanceof PdoDriver || $this->dm->hasTable($definition);
    }

    /**
     * The staff accounts as stored, by id (none before the table is made).
     *
     * @return array<string, array{active: bool, roles: list<string>}>
     */
    public function accounts(): array
    {
        $accounts = [];
        if ($this->hasTable()) {
            foreach ($this->dm->queryType(self::TYPE)->get()->items() as $record) {
                $accounts[(string) $record->getId()] = self::account($record);
            }
        }

        return $accounts;
    }

    /** @return array{active: bool, roles: list<string>} */
    public static function account(DynamicRecord $record): array
    {
        return ['active' => self::active($record->get('active')), 'roles' => RoleList::normalize($record->get('roles'))];
    }

    /** As RecordUserProvider's "active" check: null, false, 0, '0', '' and 'false' are inactive. */
    public static function active(mixed $value): bool
    {
        return !in_array($value, [null, false, 0, '0', '', 'false'], true);
    }

    /**
     * @param array<string, array{active: bool, roles: list<string>}> $accounts
     * @param array<string, list<string>> $roles staff role name => patterns
     */
    public static function count(array $accounts, array $roles): int
    {
        $count = 0;
        foreach ($accounts as $account) {
            if ($account['active'] && self::grants(self::patterns($account['roles'], $roles))) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * The patterns of these role names (an undefined name grants nothing).
     *
     * @param list<string> $names
     * @param array<string, list<string>> $roles
     * @return list<string>
     */
    public static function patterns(array $names, array $roles): array
    {
        $patterns = [];
        foreach ($names as $name) {
            array_push($patterns, ...($roles[$name] ?? []));
        }

        return array_values(array_unique($patterns));
    }

    /** @param list<string> $patterns */
    private static function grants(array $patterns): bool
    {
        foreach (self::NEEDED as $needed) {
            $granted = false;
            foreach ($patterns as $pattern) {
                $granted = $granted || Pattern::matches($pattern, $needed);
            }
            if (!$granted) {
                return false;
            }
        }

        return true;
    }
}
