<?php

declare(strict_types=1);

namespace Preflow\Folio\Install;

use Preflow\Auth\Record\RecordUserProvider;
use Preflow\Auth\SessionGuard;

/**
 * folio:install's config/folio.php and config/auth.php: where the admin lives and the guard staff
 * sign in on.
 *
 * check() reads both files and decides before anything is written. The guard Folio will use is
 * the one config/folio.php 'guard' names, else the default guard when that is a staff sign-in
 * already, else "staff" (added when missing, and named in config/folio.php so the app's default
 * guard stays). An existing guard is checked, never trusted: a SessionGuard (or a subclass) with
 * no population or 'population' => 'staff', on a provider that reads rbac's staff accounts (a
 * RecordUserProvider with 'type' => 'staff'), is set up; any other shape is a problem that stops
 * folio:install before it writes a file. A file that does not evaluate stops it too.
 *
 * The admin path is config/folio.php's when it has one (an existing value is never changed),
 * else --path, else /folio. When config/auth.php limits sessions to session.paths, the admin path
 * goes there, or the step is refused with the snippet when that list has a shape it can't edit.
 *
 * @since 0.85.0
 */
final class AuthSetup
{
    private const string GUARD = "[\n    'class' => \\Preflow\\Auth\\SessionGuard::class,\n    'provider' => 'staff',\n    'population' => 'staff',\n]";
    private const string PROVIDER = "[\n    'class' => \\Preflow\\Auth\\Record\\RecordUserProvider::class,\n    'type' => 'staff',\n    'active' => 'active',\n]";

    /** @var array<mixed>|null */
    private ?array $folio = null;

    /** @var array<mixed>|null */
    private ?array $auth = null;

    private string $adminPath = '/folio';

    private string $guard = 'staff';

    private bool $nameGuard = false;

    private bool $addGuard = false;

    private bool $addProvider = false;

    /** none (sessions start everywhere), already, add or refuse */
    private string $session = 'none';

    public function __construct(
        private readonly Setup $setup,
        private readonly ?string $pathOption,
    ) {}

    /**
     * Reads config/folio.php and config/auth.php and decides what to add.
     *
     * @return list<string> what stops folio:install before it writes anything (empty: go ahead)
     */
    public function check(): array
    {
        $problems = [];
        if ($this->setup->exists('folio.php')) {
            $this->folio = $this->setup->editor->read('folio.php');
            if ($this->folio === null) {
                return ['config/folio.php does not evaluate to an array, so folio:install cannot tell where the admin lives or which guard it uses. Fix the file and run folio:install again.'];
            }
        }
        $path = $this->folio['path'] ?? null;
        $this->adminPath = is_string($path) && $path !== '' ? $path : ($this->pathOption ?? '/folio');

        if (!$this->setup->exists('auth.php')) {
            $named = $this->folio['guard'] ?? null;
            if ($named !== null && $named !== 'staff') {
                $name = is_string($named) ? $named : get_debug_type($named);

                return ["config/folio.php names the guard \"{$name}\" ('guard'), and there is no config/auth.php to define it. folio:install writes config/auth.php with the guard \"staff\" and never changes an existing value: remove 'guard' from config/folio.php (or set it to 'staff'), and run folio:install again."];
            }

            return [];
        }
        $this->auth = $this->setup->editor->read('auth.php');
        if ($this->auth === null) {
            return ['config/auth.php does not evaluate to an array, so folio:install cannot check its guards. Fix the file and run folio:install again.'];
        }
        $guards = is_array($this->auth['guards'] ?? null) ? $this->auth['guards'] : [];
        $named = $this->folio['guard'] ?? null;
        $default = $this->auth['default_guard'] ?? null;
        if (is_string($named) && $named !== '') {
            $this->guard = $named;
        } elseif (is_string($default) && is_array($guards[$default] ?? null) && $this->guardProblems($default) === []) {
            $this->guard = $default; // Folio uses the default guard, a staff sign-in already
        } else {
            $this->guard = 'staff';
            $this->nameGuard = true;
        }

        if (!is_array($guards[$this->guard] ?? null)) {
            if ($this->guard !== 'staff') {
                return ["config/folio.php names the guard \"{$this->guard}\" ('guard'), which config/auth.php does not define. folio:install never changes an existing value: define that guard as a staff sign-in, or remove 'guard' from config/folio.php, and run folio:install again."];
            }
            $this->addGuard = true;
            $provider = 'staff';
            $problem = $this->providerProblem($provider);
            $problems = $problem === null ? [] : ["config/auth.php has {$problem}, so folio:install cannot add a staff guard on it."];
        } else {
            $problems = $this->guardProblems($this->guard);
            $provider = $guards[$this->guard]['provider'] ?? null;
        }
        if (is_string($provider) && !is_array($this->auth['providers'][$provider] ?? null)) {
            if ($provider === 'staff') {
                $this->addProvider = true;
            } else {
                $problems[] = "The guard \"{$this->guard}\" in config/auth.php signs in through the provider \"{$provider}\", which config/auth.php does not define.";
            }
        }
        if ($problems !== []) {
            return [implode(' ', $problems) . ' folio:install never changes an existing guard or provider: fix it by hand (a SessionGuard with \'population\' => \'staff\' on a RecordUserProvider with \'type\' => \'staff\'), and run folio:install again.'];
        }

        $session = $this->auth['session'] ?? null;
        if (is_array($session) && array_key_exists('paths', $session)) {
            $paths = $session['paths'];
            if (!is_array($paths) || !array_is_list($paths) || array_filter($paths, 'is_string') !== $paths) {
                $this->session = 'refuse';
            } else {
                $this->session = $this->covered($paths) ? 'already' : 'add';
            }
        }

        return [];
    }

    /** The path the admin lives at (config/folio.php's, else --path, else /folio). */
    public function adminPath(): string
    {
        return $this->adminPath;
    }

    /**
     * What is wrong with the guard $name for staff (empty: a staff sign-in). A provider it names
     * that config/auth.php does not define yet is not checked here.
     *
     * @return list<string>
     */
    private function guardProblems(string $name): array
    {
        $guard = $this->auth['guards'][$name] ?? null;
        if (!is_array($guard)) {
            return ["The guard \"{$name}\" is not defined in config/auth.php."];
        }
        $problems = [];
        $class = is_string($guard['class'] ?? null) ? ltrim($guard['class'], '\\') : '';
        if (!is_a($class, SessionGuard::class, true)) {
            $problems[] = "The guard \"{$name}\" in config/auth.php is " . ($class === '' ? 'not given a class' : "a {$class}") . ', not a ' . SessionGuard::class . '.';
        }
        $population = $guard['population'] ?? null;
        if ($population !== null && $population !== 'staff') {
            $problems[] = "The guard \"{$name}\" in config/auth.php has the population \"" . (is_scalar($population) ? (string) $population : get_debug_type($population)) . '", so everyone it signs in would get into Folio.';
        }
        $provider = $guard['provider'] ?? null;
        if (!is_string($provider)) {
            $problems[] = "The guard \"{$name}\" in config/auth.php names no provider.";
        } else {
            $problem = $this->providerProblem($provider);
            if ($problem !== null) {
                $problems[] = "The guard \"{$name}\" in config/auth.php signs in through {$problem}.";
            }
        }

        return $problems;
    }

    /** What is wrong with the provider $provider for staff (a phrase); null when it reads rbac's staff accounts or is not defined yet. */
    private function providerProblem(string $provider): ?string
    {
        $config = $this->auth['providers'][$provider] ?? null;
        if (!is_array($config)) {
            return null;
        }
        $class = is_string($config['class'] ?? null) ? ltrim($config['class'], '\\') : '';
        if (is_a($class, RecordUserProvider::class, true) && ($config['type'] ?? null) === 'staff') {
            return null;
        }

        return "the provider \"{$provider}\" (" . ($class === '' ? 'no class' : $class) . "), not rbac's staff accounts (a "
            . RecordUserProvider::class . " with 'type' => 'staff')";
    }

    /** @param list<string> $paths whether a session starts under the admin path (SessionMiddleware's prefix rule) */
    private function covered(array $paths): bool
    {
        foreach ($paths as $prefix) {
            $prefix = rtrim($prefix, '/');
            if ($prefix === '' || $this->adminPath === $prefix || str_starts_with($this->adminPath, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Spec step 3: config/folio.php written with the path, or kept as it is; the guard named when
     * staff get a guard of their own.
     *
     * @return list<Outcome>
     */
    public function folioFile(): array
    {
        $file = 'folio.php';
        $out = [];
        if ($this->folio === null) {
            $contents = Setup::template($file, ['PATH' => Setup::literal($this->adminPath)]);
            $out[] = $this->setup->dryRun
                ? new Outcome(Outcome::PLANNED, 'config/folio.php', "write it: the admin at {$this->adminPath}", $contents)
                : Outcome::fromEdit($this->setup->editor->create($file, $contents), $file, "the admin is at {$this->adminPath}");
        } elseif (!is_string($this->folio['path'] ?? null) && $this->pathOption !== null) {
            $out[] = $this->addKey($file, [], 'path', Setup::literal($this->adminPath), "'path' added: the admin is at {$this->adminPath}");
        } else {
            $note = $this->pathOption !== null && $this->pathOption !== $this->adminPath
                ? "; --path={$this->pathOption} was not applied (folio:install never changes an existing value)"
                : '';
            $out[] = new Outcome(Outcome::ALREADY, 'config/folio.php', "the admin is at {$this->adminPath}{$note}");
        }
        if ($this->nameGuard && $out[0]->status !== Outcome::REFUSED) {
            $out[] = $this->addKey($file, [], 'guard', Setup::literal($this->guard), "'guard' => '{$this->guard}' added: Folio signs staff in on their own guard");
        }

        return $out;
    }

    /**
     * Spec step 4: config/auth.php written with the staff guard as the default, or the staff
     * guard, its provider and the admin path added where they are missing.
     *
     * @return list<Outcome>
     */
    public function authFile(): array
    {
        $file = 'auth.php';
        if ($this->auth === null) {
            $contents = Setup::template($file, ['PATH' => Setup::literal($this->adminPath)]);

            return [$this->setup->dryRun
                ? new Outcome(Outcome::PLANNED, 'config/auth.php', 'write it: staff sign in on the guard "staff" (the default guard)', $contents)
                : Outcome::fromEdit($this->setup->editor->create($file, $contents), $file, "staff sign in on the guard \"staff\" (the default guard); sessions start under {$this->adminPath}")];
        }
        $out = [];
        if ($this->addGuard) {
            $default = is_string($this->auth['default_guard'] ?? null) ? " (the default guard stays \"{$this->auth['default_guard']}\")" : '';
            $out[] = $this->addKey($file, ['guards'], 'staff', self::GUARD, "the guard \"staff\" added: staff sign in on a guard of their own{$default}");
        }
        if ($this->addProvider) {
            $out[] = $this->addKey($file, ['providers'], 'staff', self::PROVIDER, 'the provider "staff" added: rbac\'s staff accounts');
        }
        if ($this->session === 'add') {
            $out[] = $this->setup->dryRun
                ? new Outcome(Outcome::PLANNED, 'config/auth.php', "add {$this->adminPath} to session.paths", Setup::literal($this->adminPath) . ',')
                : Outcome::fromEdit($this->setup->editor->addToNestedList($file, ['session', 'paths'], Setup::literal($this->adminPath)), $file, "{$this->adminPath} added to session.paths: sessions start in the admin");
        } elseif ($this->session === 'refuse') {
            $out[] = new Outcome(Outcome::REFUSED, 'config/auth.php', "session.paths is not a list of paths folio:install can add to safely. Add {$this->adminPath} to it by hand, or the sign-in seems to work and the next page asks again.", "'paths' => [/* … */ " . Setup::literal($this->adminPath) . '],');
        }
        if ($out === []) {
            $sessions = $this->session === 'none' ? 'sessions are not limited to paths' : "sessions start under {$this->adminPath}";
            $out[] = new Outcome(Outcome::ALREADY, 'config/auth.php', "staff sign in on the guard \"{$this->guard}\"; {$sessions}");
        }

        return $out;
    }

    /** @param list<string> $path */
    private function addKey(string $file, array $path, string $key, string $expression, string $done): Outcome
    {
        if ($this->setup->dryRun) {
            $where = $path === [] ? '' : ' to ' . implode(' > ', $path);

            return new Outcome(Outcome::PLANNED, 'config/' . $file, "add '{$key}'{$where}", Setup::literal($key) . ' => ' . $expression . ',');
        }

        return Outcome::fromEdit($this->setup->editor->addKey($file, $path, $key, $expression), $file, $done);
    }
}
