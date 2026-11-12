<?php

declare(strict_types=1);

namespace Preflow\Rbac\Console;

use Preflow\Core\Application;
use Preflow\DevTools\Command\CommandInterface;
use Preflow\Rbac\Staff\StaffRefused;

/**
 * staff:password <email>: sets the account's password on the server. In a terminal it asks twice
 * without echoing what is typed (stty; without it, it refuses rather than show the password);
 * otherwise it reads one line from STDIN (a pipe: printf '%s\n' "$PASSWORD" | php preflow
 * staff:password <email>). The password is never printed or taken as
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
     * @param bool|null $terminal whether $input is a terminal (default: stream_isatty())
     * @param (\Closure(string): string)|null $runStty runs `stty <args>` on the terminal and returns its output (default: shell_exec)
     */
    public function __construct(
        ?\Closure $appFactory = null,
        private readonly mixed $input = null,
        private readonly ?bool $terminal = null,
        private readonly ?\Closure $runStty = null,
    ) {
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
        if ($this->terminal ?? stream_isatty($input)) {
            // The terminal's settings, to restore after reading without echo; none: stty can't hide what is typed.
            $settings = trim($this->stty('-g'));
            if ($settings === '') {
                echo "This terminal can't hide what you type (stty is not available), so no password is asked for. Pipe it in instead: printf '%s\\n' \"\$PASSWORD\" | php preflow staff:password <email>\n";

                return 1;
            }
            $password = $this->ask($input, 'New password: ', $settings);
            if ($password !== $this->ask($input, 'Repeat it: ', $settings)) {
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

    /**
     * A line typed without echo.
     *
     * @param resource $input a terminal
     * @param string $settings the terminal's settings (stty -g), restored afterwards
     */
    private function ask(mixed $input, string $prompt, string $settings): string
    {
        echo $prompt;
        $restore = function () use ($settings): void {
            $this->stty(escapeshellarg($settings));
        };
        // An interrupted prompt (Ctrl-C, a kill) must not leave the terminal without echo.
        $previous = null;
        $signals = function_exists('pcntl_signal') && function_exists('pcntl_async_signals');
        $async = false;
        if ($signals) {
            $async = pcntl_async_signals(true);
            $previous = pcntl_signal_get_handler(SIGINT);
            pcntl_signal(SIGINT, static function () use ($restore): never {
                $restore();
                echo "\n";
                exit(130);
            });
        }
        $done = false;
        register_shutdown_function(static function () use (&$done, $restore): void {
            if (!$done) {
                $restore();
            }
        });
        $this->stty('-echo');
        try {
            $line = rtrim((string) fgets($input), "\r\n");
        } finally {
            $restore();
            $done = true;
            if ($signals) {
                pcntl_signal(SIGINT, $previous ?? SIG_DFL);
                pcntl_async_signals($async);
            }
            echo "\n";
        }

        return $line;
    }

    /** `stty $args` on the terminal; its output ('' when stty isn't there). */
    private function stty(string $args): string
    {
        return $this->runStty !== null ? ($this->runStty)($args) : (string) shell_exec('stty ' . $args . ' 2>/dev/null');
    }
}
