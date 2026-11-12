<?php

declare(strict_types=1);

namespace Preflow\Rbac\Console;

use Preflow\Core\Application;
use Preflow\DevTools\Command\CommandInterface;
use Preflow\Rbac\Staff\StaffRefused;

/**
 * staff:password <email>: sets the account's password on the server. In a terminal it asks twice
 * without echoing what is typed; otherwise it reads one line from STDIN (a pipe: printf '%s\n'
 * "$PASSWORD" | php preflow staff:password <email>). The password is never printed or taken as
 * an argument (it would stay in the shell history). Its other sessions and remember-me logins
 * end, and its invite and reset links stop working. The package lists it in composer.json
 * (extra.preflow.commands).
 */
final class StaffPasswordCommand implements CommandInterface
{
    use StaffCommand {
        StaffCommand::__construct as private command;
    }

    /**
     * @param (\Closure(): Application)|null $appFactory returns a booted application
     * @param resource|null $input where the password is read (default STDIN)
     */
    public function __construct(?\Closure $appFactory = null, private readonly mixed $input = null)
    {
        $this->command($appFactory);
    }

    public function getName(): string { return 'staff:password'; }
    public function getDescription(): string { return 'Set a staff account\'s password (asked for, or read from STDIN): staff:password <email>'; }

    public function execute(array $args): int
    {
        $accounts = self::accounts($this->app()->container());
        $staff = $accounts === null ? null : self::account($accounts, $args, "Usage: staff:password <email> (the password is asked for, or read from STDIN)\n");
        if ($staff === null) {
            return 1;
        }
        $input = $this->input ?? STDIN;
        if (stream_isatty($input)) {
            $password = self::ask($input, 'New password: ');
            if ($password !== self::ask($input, 'Repeat it: ')) {
                echo "The two passwords differ; nothing changed.\n";

                return 1;
            }
        } else {
            $password = rtrim((string) fgets($input), "\r\n");
        }
        try {
            $accounts->setPassword($staff, $password);
        } catch (StaffRefused $e) {
            return self::refused($e);
        }
        echo 'Password set for ' . $staff->get('email') . "; its other sessions and its links have ended.\n";

        return 0;
    }

    /** @param resource $input a terminal */
    private static function ask(mixed $input, string $prompt): string
    {
        echo $prompt;
        $echo = trim((string) shell_exec('stty -g 2>/dev/null'));
        shell_exec('stty -echo 2>/dev/null');
        try {
            $line = rtrim((string) fgets($input), "\r\n");
        } finally {
            shell_exec($echo !== '' ? 'stty ' . escapeshellarg($echo) . ' 2>/dev/null' : 'stty echo 2>/dev/null');
            echo "\n";
        }

        return $line;
    }
}
