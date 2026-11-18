<?php

declare(strict_types=1);

namespace Preflow\Rbac\Console;

use Preflow\DevTools\Command\CommandInterface;
use Preflow\Rbac\RoleList;
use Preflow\Rbac\Staff\StaffRefused;

/**
 * staff:roles <email> [--role=a,b | --grant=a,b] [--revoke=a,b]: shows or changes an existing
 * staff account's roles. --role replaces the list; --grant and --revoke add and remove (as
 * rbac:role does for permissions); --role with either is a usage error (exit 2). Without
 * options it prints the roles. The rules of every save apply (defined role names, the last
 * manager keeps the right to manage staff); the console has no delegation limit. A refusal
 * prints why, changes nothing and exits 1. The package lists it in composer.json
 * (extra.preflow.commands).
 *
 * @since 0.79.0
 */
final class StaffRolesCommand implements CommandInterface
{
    use StaffCommand;

    private const string USAGE = "Usage: staff:roles <email> [--role=a,b | --grant=a,b] [--revoke=a,b]\n";

    public function getName(): string { return 'staff:roles'; }
    public function getDescription(): string { return 'Show or change a staff account\'s roles: staff:roles <email> [--role=a,b | --grant=a,b] [--revoke=a,b]'; }

    public function execute(array $args): int
    {
        $role = self::option($args, 'role');
        $grant = self::option($args, 'grant');
        $revoke = self::option($args, 'revoke');
        if (count(self::arguments($args)) !== 1 || ($role !== null && ($grant !== null || $revoke !== null))) {
            echo self::USAGE;

            return 2;
        }
        $accounts = self::accounts($this->app()->container());
        $staff = $accounts === null ? null : self::account($accounts, $args, self::USAGE);
        if ($staff === null) {
            return 1;
        }
        $email = (string) $staff->get('email');
        $current = RoleList::normalize($staff->get('roles'));
        if ($role === null && $grant === null && $revoke === null) {
            echo self::describe($email, 'has', $current);

            return 0;
        }

        $new = $role !== null
            ? RoleList::normalize($role)
            : array_values(array_diff(array_values(array_unique([...$current, ...RoleList::normalize((string) $grant)])), RoleList::normalize((string) $revoke)));
        try {
            $accounts->update($staff, ['roles' => $new]);
        } catch (StaffRefused $e) {
            return self::refused($e);
        }
        echo self::describe($email, 'now has', RoleList::normalize($accounts->find($email)?->get('roles') ?? $new));

        return 0;
    }

    /** @param list<string> $roles */
    private static function describe(string $email, string $has, array $roles): string
    {
        return $roles === [] ? "{$email} {$has} no roles.\n" : "{$email} {$has} the roles: " . implode(', ', $roles) . "\n";
    }
}
