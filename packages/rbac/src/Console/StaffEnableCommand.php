<?php

declare(strict_types=1);

namespace Preflow\Rbac\Console;

use Preflow\DevTools\Command\CommandInterface;
use Preflow\Rbac\Staff\StaffRefused;

/**
 * staff:enable <email>: a disabled account may sign in again (with its password; links sent
 * before it was disabled stay void). The package lists it in composer.json (extra.preflow.commands).
 */
final class StaffEnableCommand implements CommandInterface
{
    use StaffCommand;

    public function getName(): string { return 'staff:enable'; }
    public function getDescription(): string { return 'Enable a disabled staff account: staff:enable <email>'; }

    public function execute(array $args): int
    {
        $accounts = self::accounts($this->app()->container());
        $staff = $accounts === null ? null : self::account($accounts, $args, "Usage: staff:enable <email>\n");
        if ($staff === null) {
            return 1;
        }
        try {
            $accounts->enable($staff);
        } catch (StaffRefused $e) {
            return self::refused($e);
        }
        echo 'Enabled ' . $staff->get('email') . ".\n";

        return 0;
    }
}
