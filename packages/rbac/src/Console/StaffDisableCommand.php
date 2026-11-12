<?php

declare(strict_types=1);

namespace Preflow\Rbac\Console;

use Preflow\DevTools\Command\CommandInterface;
use Preflow\Rbac\Staff\StaffRefused;

/**
 * staff:disable <email>: the account can't sign in any more; its sessions, remember-me logins and
 * links end. Refused for the last active account that may manage staff and roles. The package
 * lists it in composer.json (extra.preflow.commands).
 */
final class StaffDisableCommand implements CommandInterface
{
    use StaffCommand;

    public function getName(): string { return 'staff:disable'; }
    public function getDescription(): string { return 'Disable a staff account (its sessions and links end): staff:disable <email>'; }

    public function execute(array $args): int
    {
        $accounts = self::accounts($this->app()->container());
        $staff = $accounts === null ? null : self::account($accounts, $args, "Usage: staff:disable <email>\n");
        if ($staff === null) {
            return 1;
        }
        try {
            $accounts->disable($staff);
        } catch (StaffRefused $e) {
            return self::refused($e);
        }
        echo 'Disabled ' . $staff->get('email') . "; its sessions and links have ended.\n";

        return 0;
    }
}
