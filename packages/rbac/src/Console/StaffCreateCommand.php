<?php

declare(strict_types=1);

namespace Preflow\Rbac\Console;

use Preflow\DevTools\Command\CommandInterface;
use Preflow\Rbac\RoleList;
use Preflow\Rbac\Staff\StaffRefused;

/**
 * staff:create <email> [--name=<name>] [--role=a,b]: a new staff account, active and without a
 * password (it can't sign in yet): follow with staff:password or staff:invite. The rules of every
 * save apply (a unique e-mail, defined role names); the console has no delegation limit. Prints
 * nothing secret. The package lists it in composer.json (extra.preflow.commands).
 */
final class StaffCreateCommand implements CommandInterface
{
    use StaffCommand;

    private const string USAGE = "Usage: staff:create <email> [--name=<name>] [--role=a,b]\n";

    public function getName(): string { return 'staff:create'; }
    public function getDescription(): string { return 'Create a staff account: staff:create <email> [--name=…] [--role=a,b]; then staff:password or staff:invite'; }

    public function execute(array $args): int
    {
        $arguments = self::arguments($args);
        if (count($arguments) !== 1) {
            echo self::USAGE;

            return 1;
        }
        $accounts = self::accounts($this->app()->container());
        if ($accounts === null) {
            return 1;
        }
        try {
            $staff = $accounts->create($arguments[0], (string) self::option($args, 'name', ''), RoleList::normalize((string) self::option($args, 'role', '')));
        } catch (StaffRefused $e) {
            return self::refused($e);
        }
        $email = (string) $staff->get('email');
        $roles = RoleList::normalize($staff->get('roles'));
        echo "Created staff account {$email}" . ($roles === [] ? ' (no roles)' : ' (' . implode(', ', $roles) . ')') . ".\n"
            . "It has no password yet: set one with php preflow staff:password {$email}, or mail an invitation with php preflow staff:invite {$email}.\n";

        return 0;
    }
}
