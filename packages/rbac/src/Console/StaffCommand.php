<?php

declare(strict_types=1);

namespace Preflow\Rbac\Console;

use Preflow\Core\Container\Container;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Notify\Notifier;
use Preflow\Rbac\Messages;
use Preflow\Rbac\RoleStore;
use Preflow\Rbac\Staff\StaffAccounts;
use Preflow\Rbac\Staff\StaffRefused;
use Preflow\Rbac\StaffManagers;

/** The staff commands: rbac's command base, the app's StaffAccounts, the account an e-mail names. */
trait StaffCommand
{
    use RbacCommand;

    /** The app's staff accounts; null, after saying why, before `rbac:install`, without the staff type or before its table is made. */
    private static function accounts(Container $c): ?StaffAccounts
    {
        if (!$c->get(RoleStore::class)->installed()) {
            echo "rbac is not installed here yet; run php preflow rbac:install first.\n";

            return null;
        }
        $accounts = $c->get(StaffAccounts::class);
        if (!$accounts->enabled()) {
            echo $c->get(Messages::class)->get('staff.no_type') . "\n";

            return null;
        }
        if (!(new StaffManagers($c->get(DataManager::class)))->hasTable()) {
            echo "No staff table yet: run php preflow rbac:install (or php preflow migrate on a Folio site).\n";

            return null;
        }

        return $accounts;
    }

    /**
     * The account of the one argument (an e-mail); null, after saying why, without one or for an unknown address.
     *
     * @param list<string> $args
     */
    private static function account(StaffAccounts $accounts, array $args, string $usage): ?DynamicRecord
    {
        $arguments = self::arguments($args);
        if (count($arguments) !== 1) {
            echo $usage;

            return null;
        }
        $staff = $accounts->find($arguments[0]);
        if ($staff === null) {
            echo "No staff account has the e-mail address \"{$arguments[0]}\".\n";
        }

        return $staff;
    }

    /** Says why and fails. */
    private static function refused(StaffRefused $e): int
    {
        echo $e->getMessage() . "\n";

        return 1;
    }

    /**
     * Mails an invite or reset link and sends it now (a command has no response to send it
     * after); says what happened. 0 when it went out.
     *
     * @param \Closure(): void $queue
     */
    private static function mail(Container $c, \Closure $queue, string $sent): int
    {
        try {
            $queue();
        } catch (StaffRefused $e) {
            return self::refused($e);
        } catch (\Throwable $e) {
            echo 'The mail could not be sent (' . $e->getMessage() . "). Check the mail setup (config/notify.php).\n";

            return 1;
        }
        if (!$c->bound(Notifier::class) || $c->get(Notifier::class)->flushDeferred() === 0) {
            echo "The mail could not be sent. Check the mail setup (config/notify.php).\n";

            return 1;
        }
        echo $sent;

        return 0;
    }
}
