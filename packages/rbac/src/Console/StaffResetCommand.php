<?php

declare(strict_types=1);

namespace Preflow\Rbac\Console;

use Preflow\DevTools\Command\CommandInterface;

/**
 * staff:reset <email>: mails an active account a link to set a new password (valid for one
 * hour), on the set-password page an admin provides (StaffLinks); without one it refuses. The
 * package lists it in composer.json (extra.preflow.commands).
 */
final class StaffResetCommand implements CommandInterface
{
    use StaffCommand;

    public function getName(): string { return 'staff:reset'; }
    public function getDescription(): string { return 'Mail a staff account a link to set a new password: staff:reset <email>'; }

    public function execute(array $args): int
    {
        $c = $this->app()->container();
        $accounts = self::accounts($c);
        $staff = $accounts === null ? null : self::account($accounts, $args, "Usage: staff:reset <email>\n");
        if ($staff === null) {
            return 1;
        }

        return self::mail($c, static fn () => $accounts->resetLink($staff), 'Reset link sent to ' . $staff->get('email') . ".\n");
    }
}
