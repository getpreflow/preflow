<?php

declare(strict_types=1);

namespace Preflow\Folio\Console;

use Preflow\DevTools\Command\CommandInterface;
use Preflow\Folio\Install\AuthSetup;
use Preflow\Folio\Install\CommandsSetup;
use Preflow\Folio\Install\MediaSetup;
use Preflow\Folio\Install\Outcome;
use Preflow\Folio\Install\ProvidersSetup;
use Preflow\Folio\Install\RolesSetup;
use Preflow\Folio\Install\Setup;

/**
 * folio:install [--path=/folio] [--email=] [--name=] [--no-password] [--dry-run]: wires Folio and
 * preflow/rbac into the app in the working directory, as the guide "Add Folio to an app" does by
 * hand, and creates the first admin.
 *
 * 1. Without preflow/rbac it stops before writing anything (with preflow/roles it points at the
 *    guide: there staff and their password hashes live in config/auth.php, a committed file).
 * 2. Before writing, config/folio.php and config/auth.php are read and checked (AuthSetup); an
 *    existing guard of the wrong shape stops it with a message, and nothing is written.
 * 3. config/folio.php, config/auth.php and config/media.php are written or added to; then
 *    config/providers.php (Folio and rbac before the app's own), config/roles.php (declared
 *    permissions only, read from the app booted with Folio), `migrate`, `rbac:install`,
 *    `folio:extensions:enable rbac`, `folio:extensions:apply`, and the first admin.
 *
 * Existing files are edited only by checked insertions (devtools' ConfigFileEditor) and never
 * changed where a value is there: an edit it cannot make safely leaves the file as it is, prints
 * the snippet to add by hand, and the command exits non-zero at the end, after every step it
 * could do. Every step recognises that it is done, so running it again is safe and finishes what
 * a broken run left. --dry-run prints what it would write and run.
 *
 * The admin's password is asked for in a terminal, without echo, or read as one line from STDIN
 * when it is piped; never taken as an argument. Without a terminal and without --email it stops
 * at once, naming the options.
 *
 * @since 0.85.0
 */
final class InstallCommand implements CommandInterface
{
    private const string USAGE = "Usage: preflow folio:install [--path=/folio] [--email=<address>] [--name=<name>] [--no-password] [--dry-run]\n";
    private const string GUIDE = 'https://preflow.dev/docs/folio/folio-setup';

    /** @var resource|null */
    private $input;

    private ?string $url = null;

    /** The password piped in (read before anything is written, so a missing one stops the command early). */
    private ?string $piped = null;

    /**
     * @param string|null $basePath the app's folder (default: the working directory)
     * @param resource|null $input where the admin's details and password are read (default STDIN)
     * @param bool|null $terminal whether $input is a terminal (default: stream_isatty())
     * @param (\Closure(string): string)|null $runStty runs `stty <args>` on the terminal and returns its output (default: shell_exec)
     */
    public function __construct(
        private readonly ?string $basePath = null,
        mixed $input = null,
        private readonly ?bool $terminal = null,
        private readonly ?\Closure $runStty = null,
    ) {
        $this->input = is_resource($input) ? $input : null;
    }

    public function getName(): string { return 'folio:install'; }
    public function getDescription(): string { return 'Add Folio and rbac to this app and create the first admin: folio:install [--path=/folio] [--email=…] [--name=…] [--no-password] [--dry-run]'; }

    public function execute(array $args): int
    {
        $options = $this->options($args);
        if (is_string($options)) {
            echo $options . self::USAGE;

            return 1;
        }
        $root = rtrim($this->basePath ?? (getcwd() ?: '.'), '/');
        $packages = $this->packages($root);
        if (!in_array('preflow/rbac', $packages, true)) {
            echo "folio:install sets Folio up with preflow/rbac, which keeps the staff accounts and their roles in the database. It is not installed:\n\n"
                . "    composer require preflow/rbac\n\nthen run php preflow folio:install again. Nothing was changed.\n";
            if (in_array('preflow/roles', $packages, true)) {
                echo "\nThis app has preflow/roles. With it, staff and their password hashes live in config/auth.php, a committed file, so folio:install does not set it up; the guide does it by hand: " . self::GUIDE . "\n";
            }

            return 1;
        }

        $setup = new Setup($root, $options['dry-run']);
        $terminal = $this->terminal ?? stream_isatty($this->input());
        if (!$setup->dryRun && $options['email'] === null && !$terminal && ($setup->console->probe('state')['staff'] ?? 0) === 0) {
            echo "folio:install creates the first admin and asks for its e-mail address, name and password, but there is no terminal to ask in.\n"
                . "Pass --email=<address> [--name=<name>], and pipe the password in (printf '%s\\n' \"\$PASSWORD\" | php preflow folio:install --email=<address>)\n"
                . "or add --no-password (then set it later with php preflow staff:password <address>, or staff:invite). Nothing was changed.\n";

            return 1;
        }
        if (!$setup->dryRun && $options['email'] !== null && !$terminal && !$options['no-password']) {
            $this->piped = rtrim((string) fgets($this->input()), "\r\n");
            if ($this->piped === '' && ($setup->console->probe('state', [$options['email']])['exists'] ?? false) !== true) {
                echo "No password came on STDIN for {$options['email']}. Pipe it in (printf '%s\\n' \"\$PASSWORD\" | php preflow folio:install --email={$options['email']}),\n"
                    . "or add --no-password (then set it later with php preflow staff:password {$options['email']}, or staff:invite). Nothing was changed.\n";

                return 1;
            }
        }

        $auth = new AuthSetup($setup, $options['path']);
        $problems = $auth->check();
        if ($problems !== []) {
            echo implode("\n", $problems) . "\nNothing was changed.\n";

            return 1;
        }

        echo 'Setting up Folio' . ($setup->dryRun ? ' (dry run: nothing is written or run)' : '') . ", the admin at {$auth->adminPath()}:\n\n";
        $outcomes = [];
        $step = function (Outcome ...$done) use (&$outcomes): void {
            foreach ($done as $outcome) {
                $outcomes[] = $outcome;
                $this->print($outcome);
            }
        };
        $step(...$auth->folioFile());
        $step(...$auth->authFile());
        $authReady = !array_filter($outcomes, static fn (Outcome $o): bool => $o->needsYou());
        $step((new MediaSetup($setup))->run());

        if ($authReady) {
            $step($providers = (new ProvidersSetup($setup))->run());
            $ready = !$providers->needsYou();
        } else {
            $step(new Outcome(Outcome::SKIPPED, 'config/providers.php', 'Folio is not listed until config/folio.php and config/auth.php are set up: Folio would not boot'));
            $ready = false;
        }
        if ($ready) {
            $step((new RolesSetup($setup))->run());
            $commands = (new CommandsSetup($setup))->run();
            $step(...$commands);
            $ready = !array_filter($commands, static fn (Outcome $o): bool => $o->needsYou());
        } else {
            $step(new Outcome(Outcome::SKIPPED, 'config/roles.php', 'the app must boot with Folio and rbac first'));
            $step(new Outcome(Outcome::SKIPPED, 'php preflow migrate …', 'migrate, rbac:install, folio:extensions:enable rbac and folio:extensions:apply wait for the files above'));
        }
        $step($ready ? $this->admin($setup, $options) : new Outcome(Outcome::SKIPPED, 'first admin', 'the steps before it did not finish'));

        $this->summary($outcomes, $auth->adminPath(), $setup->dryRun);

        return array_filter($outcomes, static fn (Outcome $o): bool => $o->needsYou()) === [] ? 0 : 1;
    }

    /**
     * The options, or what is wrong with them.
     *
     * @param array<string> $args
     * @return array{path: ?string, email: ?string, name: ?string, no-password: bool, dry-run: bool}|string
     */
    private function options(array $args): array|string
    {
        $options = ['path' => null, 'email' => null, 'name' => null, 'no-password' => false, 'dry-run' => false];
        foreach ($args as $arg) {
            if ($arg === '--no-password' || $arg === '--dry-run') {
                $options[substr($arg, 2)] = true;
                continue;
            }
            if (preg_match('/^--(path|email|name)=(.*)$/s', $arg, $m) === 1) {
                $options[$m[1]] = trim($m[2]);
                continue;
            }
            if (str_starts_with($arg, '-')) {
                // --password=… lands here too: a password is never an argument (it would stay in the shell history).
                return 'Unknown option ' . explode('=', $arg, 2)[0] . ".\n";
            }

            return "Unexpected argument \"{$arg}\".\n";
        }
        if ($options['email'] === '') {
            return "--email needs an address.\n";
        }
        if ($options['path'] !== null && preg_match('~^(/[A-Za-z0-9][A-Za-z0-9._-]*)+$~', $options['path']) !== 1) {
            return "--path is the admin's address, such as /folio or /admin: a slash, then letters, digits, dots, dashes or underscores.\n";
        }

        return $options;
    }

    /**
     * The packages vendor/composer/installed.json lists.
     *
     * @return list<string>
     */
    private function packages(string $root): array
    {
        $file = $root . '/vendor/composer/installed.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $packages = is_array($data) ? ($data['packages'] ?? $data) : [];
        $names = [];
        foreach (is_array($packages) ? $packages : [] as $package) {
            if (is_array($package) && is_string($package['name'] ?? null)) {
                $names[] = $package['name'];
            }
        }

        return $names;
    }

    /**
     * Spec step 9: the first admin (staff role admin), unless the site has staff accounts already
     * (or the --email one). Its details come from the options, else a terminal; the password from
     * the terminal without echo, or one line of piped input; --no-password leaves it unset.
     *
     * @param array{path: ?string, email: ?string, name: ?string, no-password: bool, dry-run: bool} $options
     */
    private function admin(Setup $setup, array $options): Outcome
    {
        $subject = 'first admin';
        $email = $options['email'];
        if ($setup->dryRun) {
            return new Outcome(Outcome::PLANNED, $subject, 'create it with the role admin' . ($email !== null ? ": {$email}" : ', asked for') . ($options['no-password'] ? ', without a password' : ''));
        }
        $state = $setup->console->probe('state', $email !== null ? [$email] : []);
        $this->url = is_string($state['url'] ?? null) ? $state['url'] : null;
        $staff = $state['staff'] ?? null;
        if (!is_int($staff)) {
            $why = $state['error'] ?? $state['boot'] ?? 'rbac\'s staff type or its table is missing';

            return new Outcome(Outcome::FAILED, $subject, 'the staff accounts are not ready (' . (is_string($why) ? $why : '?') . ')');
        }
        if ($email !== null && ($state['exists'] ?? false) === true) {
            return new Outcome(Outcome::ALREADY, $subject, "{$email} has a staff account");
        }
        if ($email === null && $staff > 0) {
            return new Outcome(Outcome::ALREADY, $subject, "the site has {$staff} staff account" . ($staff === 1 ? '' : 's') . '; add more with php preflow staff:create');
        }

        $input = $this->input();
        $terminal = $this->terminal ?? stream_isatty($input);
        echo "\n";
        if ($email === null) {
            $email = $terminal ? $this->line($input, 'E-mail address of the first admin: ') : '';
            if ($email === '') {
                return new Outcome(Outcome::FAILED, $subject, 'no e-mail address: run folio:install again with --email=<address>');
            }
        }
        $name = $options['name'] ?? ($terminal ? $this->line($input, 'Name: ') : '');
        $password = null;
        if (!$options['no-password']) {
            $password = $terminal ? $this->password($input, (int) ($state['password_min'] ?? 10)) : ($this->piped ?? rtrim((string) fgets($input), "\r\n"));
            if ($password === null) {
                return new Outcome(Outcome::FAILED, $subject, 'no password set; run folio:install again, or add --no-password and set it with php preflow staff:password <address>');
            }
            if ($password === '') {
                return new Outcome(Outcome::FAILED, $subject, "no password came on STDIN: pipe one in (printf '%s\\n' \"\$PASSWORD\" | php preflow folio:install --email={$email}), or add --no-password");
            }
        }

        $answer = $setup->console->probe('admin', [], (string) json_encode(['email' => $email, 'name' => $name, 'password' => $password]));
        $password = $this->piped = null;

        return match ($answer['status'] ?? null) {
            'created' => new Outcome(Outcome::RAN, $subject, 'created the admin ' . (is_string($answer['email'] ?? null) ? $answer['email'] : $email) . ' (role admin)'
                . ($options['no-password'] ? "; it has no password yet: set one with php preflow staff:password {$email}, or mail an invitation with php preflow staff:invite {$email}" : '')),
            'exists' => new Outcome(Outcome::ALREADY, $subject, "{$email} has a staff account"),
            'refused' => new Outcome(Outcome::FAILED, $subject, 'not created: ' . (is_string($answer['message'] ?? null) ? $answer['message'] : '?')),
            default => new Outcome(Outcome::FAILED, $subject, 'not created: ' . (is_string($answer['error'] ?? null) ? $answer['error'] : 'no answer')),
        };
    }

    /** @return resource */
    private function input(): mixed
    {
        return $this->input ?? STDIN;
    }

    /** @param resource $input */
    private function line(mixed $input, string $prompt): string
    {
        echo $prompt;

        return trim((string) fgets($input));
    }

    /**
     * A password typed twice without echo, at least $min characters; three tries. Null when the
     * terminal can't hide what is typed (stty is missing) or no try matched.
     *
     * @param resource $input a terminal
     */
    private function password(mixed $input, int $min): ?string
    {
        $settings = trim($this->stty('-g'));
        if ($settings === '') {
            echo "This terminal can't hide what you type (stty is not available), so no password is asked for. Pipe it in instead, or use --no-password.\n";

            return null;
        }
        for ($try = 0; $try < 3; $try++) {
            $password = $this->hidden($input, "Password (at least {$min} characters): ", $settings);
            if (mb_strlen($password) < $min) {
                echo "That is shorter than {$min} characters.\n";
                continue;
            }
            if ($password !== $this->hidden($input, 'Repeat it: ', $settings)) {
                echo "The two passwords differ.\n";
                continue;
            }

            return $password;
        }

        return null;
    }

    /**
     * A line typed without echo; the terminal's settings are restored afterwards, also when the
     * prompt is interrupted (Ctrl-C) or the process ends.
     *
     * @param resource $input a terminal
     * @param string $settings the terminal's settings (stty -g)
     */
    private function hidden(mixed $input, string $prompt, string $settings): string
    {
        echo $prompt;
        $restore = function () use ($settings): void {
            $this->stty(escapeshellarg($settings));
        };
        // An interrupted prompt (Ctrl-C, a kill, an exit) must not leave the terminal without echo.
        $signals = function_exists('pcntl_signal') && function_exists('pcntl_async_signals');
        $previous = $signals ? pcntl_signal_get_handler(SIGINT) : SIG_DFL;
        $async = $signals && pcntl_async_signals(true);
        if ($signals) {
            pcntl_signal(SIGINT, static function () use ($restore): never {
                $restore();
                echo "\n";
                exit(130);
            });
        }
        $prompt = new \ArrayObject(['open' => true]);
        register_shutdown_function(static function () use ($prompt, $restore): void {
            if ($prompt['open'] === true) {
                $restore();
            }
        });
        $this->stty('-echo');
        try {
            $line = rtrim((string) fgets($input), "\r\n");
        } finally {
            $restore();
            $prompt['open'] = false;
            if ($signals) {
                pcntl_signal(SIGINT, $previous);
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

    private function print(Outcome $outcome): void
    {
        $label = match ($outcome->status) {
            Outcome::WRITTEN => 'written: ',
            Outcome::CHANGED => 'changed: ',
            Outcome::ALREADY => 'already set up: ',
            Outcome::REFUSED => 'NEEDS YOU: ',
            Outcome::SKIPPED => 'skipped: ',
            Outcome::RAN => 'done: ',
            Outcome::FAILED => 'FAILED: ',
            default => 'would ',
        };
        echo '  ' . str_pad($outcome->subject, 24) . ' ' . ($outcome->message === '' ? rtrim($label, ': ') : $label . $outcome->message) . "\n";
        if (trim($outcome->output) !== '') {
            echo self::indent(rtrim($outcome->output), 6) . "\n";
        }
        if ($outcome->snippet !== null) {
            echo self::indent(rtrim($outcome->snippet), 6) . "\n";
        }
    }

    private static function indent(string $text, int $by): string
    {
        $pad = str_repeat(' ', $by);

        return $pad . str_replace("\n", "\n" . $pad, $text);
    }

    /**
     * Spec step 10: files written and changed, steps set up already, skipped or needing the
     * developer, and the admin's address.
     *
     * @param list<Outcome> $outcomes
     */
    private function summary(array $outcomes, string $adminPath, bool $dryRun): void
    {
        echo "\nSummary\n";
        $groups = [
            'Written' => [Outcome::WRITTEN],
            'Changed' => [Outcome::CHANGED],
            'Already set up' => [Outcome::ALREADY],
            'Skipped' => [Outcome::SKIPPED],
            'Needs you (see above)' => [Outcome::REFUSED, Outcome::FAILED],
        ];
        foreach ($groups as $title => $statuses) {
            $subjects = [];
            foreach ($outcomes as $outcome) {
                if (in_array($outcome->status, $statuses, true)) {
                    $subjects[$outcome->subject] = true;
                }
            }
            if ($subjects !== []) {
                echo '  ' . str_pad($title . ':', 23) . ' ' . implode(', ', array_keys($subjects)) . "\n";
            }
        }
        if ($dryRun) {
            echo "  Dry run: nothing was written or run.\n";

            return;
        }
        $admin = rtrim($this->url ?? '', '/') . $adminPath;
        echo "\nThe admin: {$admin} (sign in at {$admin}/login" . ($this->url === null ? '' : '; start the app with php preflow serve') . ").\n";
    }
}
