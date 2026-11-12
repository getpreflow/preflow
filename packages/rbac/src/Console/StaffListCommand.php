<?php

declare(strict_types=1);

namespace Preflow\Rbac\Console;

use Preflow\Data\DataManager;
use Preflow\DevTools\Command\CommandInterface;
use Preflow\Rbac\RoleList;
use Preflow\Rbac\StaffManagers;

/**
 * staff:list: the staff accounts, one per line by e-mail: e-mail, name, roles and whether the
 * account is active, disabled or still without a password. The package lists it in
 * composer.json (extra.preflow.commands).
 */
final class StaffListCommand implements CommandInterface
{
    use StaffCommand;

    public function getName(): string { return 'staff:list'; }
    public function getDescription(): string { return 'List the staff accounts: e-mail, name, roles, state'; }

    public function execute(array $args): int
    {
        $c = $this->app()->container();
        $accounts = self::accounts($c);
        if ($accounts === null) {
            return 1;
        }
        $rows = [];
        foreach ($c->get(DataManager::class)->queryType(StaffManagers::TYPE)->get()->items() as $staff) {
            $roles = RoleList::normalize($staff->get('roles'));
            $state = !StaffManagers::active($staff->get('active')) ? 'disabled' : ((string) $staff->get('password_hash') === '' ? 'no password yet' : 'active');
            $rows[(string) $staff->get('email')] = (string) $staff->get('email') . "\t" . (string) $staff->get('name') . "\t"
                . ($roles === [] ? '(no roles)' : implode(', ', $roles)) . "\t" . $state;
        }
        if ($rows === []) {
            echo "No staff accounts yet. Create one with php preflow staff:create <email> --role=admin.\n";

            return 0;
        }
        ksort($rows);
        echo implode("\n", $rows) . "\n";

        return 0;
    }
}
