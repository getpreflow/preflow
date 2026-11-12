<?php

declare(strict_types=1);

namespace Preflow\Rbac\Console;

use Preflow\DevTools\Command\CommandInterface;

/**
 * staff:invite <email>: mails the account a link to choose its password (valid for 7 days;
 * earlier invitations stop working), for an active account without a password. The link opens
 * the set-password page an admin provides (StaffLinks); without one it refuses. The package
 * lists it in composer.json (extra.preflow.commands).
 */
final class StaffInviteCommand implements CommandInterface
{
    use StaffCommand;

    public function getName(): string { return 'staff:invite'; }
    public function getDescription(): string { return 'Mail a staff account an invitation to choose its password: staff:invite <email>'; }

    public function execute(array $args): int
    {
        $c = $this->app()->container();
        $accounts = self::accounts($c);
        $staff = $accounts === null ? null : self::account($accounts, $args, "Usage: staff:invite <email>\n");
        if ($staff === null) {
            return 1;
        }

        return self::mail($c, static fn () => $accounts->invite($staff), 'Invitation sent to ' . $staff->get('email') . ".\n");
    }
}
