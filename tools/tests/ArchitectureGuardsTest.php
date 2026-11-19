<?php

declare(strict_types=1);

namespace Preflow\Tools\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Architecture guards across the whole monorepo. Each test is one guard; its name is the id that
 * principle records cite as their enforcement.
 *
 * architecture.no-folio-in-core: the packages run without Folio. No package's source names the
 * Preflow\Folio namespace, except preflow/folio itself and the packages whose composer.json
 * *requires* preflow/folio (today: starter-folio). A package that only suggests Folio (members,
 * rbac) is core and stays Folio-free; it offers Folio integration through its own public
 * interfaces that Folio binds. The source of a package is every directory its composer.json maps
 * under autoload.psr-4 (src/ for libraries, app/ for the starters and the skeleton), all files.
 * The packages' own NoFolioDependency tests stay: they ship with each split repository and also
 * pin that package's composer shape; this guard is the single complete one across all packages.
 *
 * mail.no-folio-name: no mail says "Folio" (a recipient never sees the admin's product name). The
 * mail words live in two places, and both are checked in every package and locale:
 *  - mail Twig templates: every *.twig below a templates/mail/ directory of a package (today
 *    notify's templates/mail/layout.twig, the HTML part of every composed mail); Twig comments
 *    are removed first, since they never reach a mail;
 *  - mail texts in the lang files: the whole of every packages/{pkg}/lang/{locale}/*mail*.php
 *    file (rbac's rbac-mail.php) and the 'mail' key of every other lang file (folio.php's
 *    folio.mail.*, members.php's members.mail.*).
 * Mail-sending classes (MemberMailer, StaffMail, Folio's StaffMailer, notify's BasicMailLayout)
 * take every word from these keys and add markup only, so their PHP is not scanned.
 *
 * storage.no-driver-class-checks: outside packages/data/src/Driver/ no source asks a storage driver
 * for its class: no `instanceof` and no parameter type naming PdoDriver, MysqlDriver, SqliteDriver
 * or JsonFileDriver (short, `Driver\…` or fully qualified). Code that needs an ability asks for its
 * capability interface (RunsSql, PdoConnection, Transactions, AtomicCounters, UniqueIndexes,
 * RankedSearch, DescribesColumns), so a new driver gets the same treatment by implementing it.
 * Building a driver (`new …`) is allowed (core's factory, preflow/testing). Scanned: every
 * package's psr-4 source roots and tools/Convert.
 *
 * packages.requires-what-it-names: a package whose source names another package's class (or
 * interface, trait, enum) that says `@since X.Y` in its docblock keeps Composer from pairing it with
 * a release before X.Y: its `conflict` for that package is `<X.Y` or later, and where it requires
 * the package, the require's lowest version is X.Y or later. A constraint can't be tightened for a
 * version after it is published, so this runs before every release. Methods are not covered (their
 * @since sits on the method); the per-package ComposerConstraintsTest names those.
 *
 * architecture.no-ambient-state: package code reads who is looking, the locale, the read mode and
 * the time from the render context (Contexts::current()), not from state that belongs to the
 * process or the request. Every package's psr-4 source roots are scanned (every *.php file), line by
 * line with comment lines skipped, for the rules in AMBIENT_RULES:
 *  - superglobal: $_SERVER, $_COOKIE, $_SESSION, $_GET, $_POST, $_REQUEST, $_FILES, and
 *    fromGlobals(), which turns them into a request;
 *  - clock: time(), microtime(), hrtime(), date()/gmdate() with only a format, strtotime() of a
 *    string, new DateTime(Immutable) with no argument, 'now' or a relative string; a line that
 *    ends with `// clock: stamp` (a timing for the debug collector, a file name, a log line) is
 *    no hit;
 *  - setter: the ambient setters (setLocale, setUser, addUser, showWorkingCopies,
 *    ContentLocale::set, ->set($locale) with the locale as its only argument);
 *  - static-state: a static property or static variable (state shared by every render);
 *  - context-property: a property or promoted parameter typed RenderContext (services read
 *    Contexts::current(), they don't keep a copy);
 *  - peek: Contexts::peek(), only for code with a documented default outside a render;
 *  - context-dump: var_export(), get_object_vars() or an (array) cast of a context (they walk its
 *    properties and reach the request; serialize, json_encode and var_dump give the safe form);
 *  - frame-context-write: setting a frame's context from outside it (only Contexts narrows);
 *  - request-binding: binding request state (RequestContext, CookieQueue) into the container,
 *    where every later render would find it.
 * Two files decide what may stay. tools/tests/ambient-state-allow.php is permanent: per file (a
 * glob is allowed) and rule, a reason, or per line text a reason; each entry must still match a
 * hit. tools/tests/ambient-state-baseline.php is the ratchet: today's hits that the render-context
 * release moves onto the context, one entry per hit, as the trimmed line text (line numbers move).
 * A hit in neither file fails; a baseline entry that no longer hits fails too and must be removed,
 * so the baseline only shrinks.
 */
final class ArchitectureGuardsTest extends TestCase
{
    private const FOLIO = 'preflow/folio';

    private const DRIVERS = '(?:Preflow\\\\Data\\\\Driver\\\\|Driver\\\\)?(PdoDriver|MysqlDriver|SqliteDriver|JsonFileDriver)';

    /**
     * A driver class check that must stay for a release names its file and line here; none since
     * 0.78. Each line must be found exactly once; remove the entry when its line goes.
     *
     * @var array<string, list<string>>
     */
    private const PENDING_DRIVER_CHECKS = [];

    /** A context in an expression: Contexts::current()/peek(), or a variable or property named …context or …ctx. */
    private const CONTEXT_EXPRESSION = '(?:\\\\?(?:\w+\\\\)*Contexts::(?:current|peek)\(\)|\$[\w>-]*?(?:[cC]ontext|ctx)\b(?!\s*\[))';

    /**
     * The rules of architecture.no-ambient-state: rule => pattern for one source line (see the class
     * docblock). A clock line ending in `// clock: stamp` is exempt; comment lines are skipped.
     */
    private const AMBIENT_RULES = [
        'superglobal' => '/\$_(?:SERVER|COOKIE|SESSION|GET|POST|REQUEST|FILES)\b|\bfromGlobals\(/',
        'clock' => '/(?<![\w$>:])(?<!function )\\\\?(?:time\(\)|(?:microtime|hrtime)\('
            . '|(?:gm)?date\(\s*(?:\'[^\']*\'|"[^"]*"|\\\\?DATE_\w+|\\\\?DateTime(?:Interface)?::\w+)\s*\)'
            . '|strtotime\(\s*(?:\'[^\']*\'|"[^"]*")\s*\))'
            . '|\bnew\s+\\\\?DateTime(?:Immutable)?\(\s*(?:\)|[\'"]now[\'"]|\'[-+]\d|\'-\'\s*\.'
            . '|\'(?:today|tomorrow|yesterday|midnight|noon|first |last |next |this ))/i',
        'setter' => '/->(?:setLocale|setUser|addUser|showWorkingCopies)\(|ContentLocale::set\b|->set\(\$?locale\s*\)/',
        'static-state' => '/^(?!.*\breadonly\b).*(?<![\w$>:\\\\])static\s+(?!function\b|fn\b)(?:\??[A-Za-z_\\\\][\w\\\\|&]*\s+)?\$\w+/',
        'context-property' => '/\b(?:public|protected|private|readonly|var)(?:\(set\))?\s+'
            . '(?:(?:public|protected|private|readonly|static|final)(?:\(set\))?\s+)*'
            . '(?:\??[\w\\\\]+\s*[|&]\s*)*\??\\\\?(?:\w+\\\\)*RenderContext\s*(?:[|&]\s*\??[\w\\\\]+\s*)*\$\w+/',
        'peek' => '/\bContexts::peek\(/',
        'context-dump' => '/\b(?:var_export|get_object_vars)\(\s*' . self::CONTEXT_EXPRESSION . '|\(array\)\s*' . self::CONTEXT_EXPRESSION . '/',
        'frame-context-write' => '/(?<!\$this)->context\s*(?:\?\?)?=(?![=>])/',
        'request-binding' => '/->instance\(\s*\\\\?(?:\w+\\\\)*(?:RequestContext|CookieQueue)::class/',
    ];

    private static function packages(): string
    {
        return dirname(__DIR__, 2) . '/packages';
    }

    public function test_architecture_no_folio_in_core(): void
    {
        $hits = [];
        $scanned = [];
        $exempt = [];
        foreach (glob(self::packages() . '/*/composer.json') ?: [] as $composerFile) {
            $dir = dirname($composerFile);
            $composer = json_decode((string) file_get_contents($composerFile), true, flags: JSON_THROW_ON_ERROR);
            if (($composer['name'] ?? null) === self::FOLIO || isset($composer['require'][self::FOLIO])) {
                $exempt[] = basename($dir);
                continue;
            }
            $roots = array_values((array) ($composer['autoload']['psr-4'] ?? []));
            $roots = $roots === [] ? ['src/'] : $roots;
            foreach ($roots as $root) {
                foreach ((array) $root as $path) {
                    $sourceDir = $dir . '/' . rtrim($path, '/');
                    if (!is_dir($sourceDir)) {
                        continue;
                    }
                    $scanned[basename($dir)] = true;
                    foreach ($this->files($sourceDir) as $file) {
                        foreach (file($file) ?: [] as $n => $line) {
                            if (preg_match('/Preflow\\\\{1,2}Folio\b/', $line) === 1) {
                                $hits[] = substr($file, strlen(self::packages()) + 1) . ':' . ($n + 1) . ': ' . trim($line);
                            }
                        }
                    }
                }
            }
        }

        sort($exempt);
        $this->assertSame(['folio', 'starter-folio'], $exempt, 'the packages allowed to name Folio');
        $this->assertArrayHasKey('core', $scanned);
        $this->assertArrayHasKey('members', $scanned, 'suggesting Folio does not exempt a package');
        $this->assertArrayHasKey('starter', $scanned, 'the starters are scanned by their app/ source');
        $this->assertSame([], $hits, 'core packages must not name Preflow\\Folio');
    }

    public function test_mail_no_folio_name(): void
    {
        $hits = [];
        $sources = [];

        foreach (glob(self::packages() . '/*/templates/mail') ?: [] as $mailDir) {
            foreach ($this->files($mailDir) as $file) {
                if (!str_ends_with($file, '.twig')) {
                    continue;
                }
                $sources[] = $this->relative($file);
                $text = (string) preg_replace('/\{#.*?#\}/s', '', (string) file_get_contents($file));
                if (preg_match('/\bfolio\b/i', $text) === 1) {
                    $hits[] = $this->relative($file);
                }
            }
        }

        foreach (glob(self::packages() . '/*/lang/*/*.php') ?: [] as $file) {
            $texts = require $file;
            if (!is_array($texts)) {
                continue;
            }
            $prefix = basename($file, '.php');
            if (!str_contains($prefix, 'mail')) {
                if (!isset($texts['mail'])) {
                    continue;
                }
                $texts = ['mail' => $texts['mail']];
            }
            $sources[] = $this->relative($file);
            array_walk_recursive($texts, function (mixed $value, int|string $key) use (&$hits, $file): void {
                if (is_string($value) && preg_match('/\bfolio\b/i', $value) === 1) {
                    $hits[] = $this->relative($file) . ' [' . $key . ']: ' . $value;
                }
            });
        }

        foreach (['notify/templates/mail/layout.twig', 'rbac/lang/en/rbac-mail.php', 'folio/lang/en/folio.php', 'members/lang/en/members.php'] as $known) {
            $this->assertContains($known, $sources, 'a known mail source is checked');
        }
        $this->assertSame([], $hits, 'mails must not say Folio');
    }

    public function test_storage_no_driver_class_checks(): void
    {
        $driverDir = self::packages() . '/data/src/Driver/';
        $sources = [];
        foreach (glob(self::packages() . '/*/composer.json') ?: [] as $composerFile) {
            $dir = dirname($composerFile);
            $composer = json_decode((string) file_get_contents($composerFile), true, flags: JSON_THROW_ON_ERROR);
            $roots = array_values((array) ($composer['autoload']['psr-4'] ?? []));
            $roots = $roots === [] ? ['src/'] : $roots;
            foreach ($roots as $root) {
                foreach ((array) $root as $path) {
                    $sourceDir = $dir . '/' . rtrim($path, '/');
                    if (is_dir($sourceDir)) {
                        $sources[basename($dir)][] = $sourceDir;
                    }
                }
            }
        }
        $sources['tools/Convert'] = [dirname(__DIR__) . '/Convert'];

        $hits = [];
        foreach ($sources as $sourceDirs) {
            foreach ($sourceDirs as $sourceDir) {
                foreach ($this->files($sourceDir) as $file) {
                    if (str_starts_with($file, $driverDir) || !str_ends_with($file, '.php')) {
                        continue;
                    }
                    foreach (file($file) ?: [] as $n => $line) {
                        if (self::checksDriverClass($line)) {
                            $hits[] = [$this->relativeToRepo($file), $n + 1, trim($line)];
                        }
                    }
                }
            }
        }

        $unexpected = [];
        $pendingSeen = [];
        foreach ($hits as [$file, $lineNo, $text]) {
            $pendingFile = str_starts_with($file, 'packages/') ? substr($file, strlen('packages/')) : $file;
            if (in_array($text, self::PENDING_DRIVER_CHECKS[$pendingFile] ?? [], true)) {
                $pendingSeen[$pendingFile . ': ' . $text] = ($pendingSeen[$pendingFile . ': ' . $text] ?? 0) + 1;
                continue;
            }
            $unexpected[] = $file . ':' . $lineNo . ': ' . $text;
        }

        foreach (['auth', 'core', 'folio', 'data', 'tools/Convert'] as $package) {
            $this->assertArrayHasKey($package, $sources, 'a scanned source');
        }
        $this->assertSame([], $unexpected, 'ask for a capability interface, not a driver class');
        foreach (self::PENDING_DRIVER_CHECKS as $file => $lines) {
            foreach ($lines as $text) {
                $this->assertSame(
                    1,
                    $pendingSeen[$file . ': ' . $text] ?? 0,
                    "pending driver check {$file}: {$text} must be found exactly once (remove a stale entry)",
                );
            }
        }
    }

    /**
     * Every package must require each package whose namespace it names, at a version that carries
     * the classes it uses (@since).
     */
    public function test_packages_requires_what_it_names(): void
    {
        $namespaces = [];
        $packages = [];
        foreach (glob(self::packages() . '/*/composer.json') ?: [] as $composerFile) {
            $dir = dirname($composerFile);
            $composer = json_decode((string) file_get_contents($composerFile), true, flags: JSON_THROW_ON_ERROR);
            $packages[$dir] = $composer;
            foreach ((array) ($composer['autoload']['psr-4'] ?? []) as $prefix => $path) {
                $namespaces[$prefix] = [(string) $composer['name'], $dir . '/' . rtrim((string) $path, '/')];
            }
        }
        uksort($namespaces, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $missing = [];
        $checked = 0;
        foreach ($packages as $dir => $composer) {
            if (self::isStarter($composer)) {
                continue;
            }
            $name = (string) $composer['name'];
            foreach ((array) ($composer['autoload']['psr-4'] ?? []) as $path) {
                $sourceDir = $dir . '/' . rtrim((string) $path, '/');
                if (!is_dir($sourceDir)) {
                    continue;
                }
                foreach ($this->files($sourceDir) as $file) {
                    if (!str_ends_with($file, '.php')) {
                        continue;
                    }
                    preg_match_all('/(?<![A-Za-z0-9_])(Preflow(?:\\\\[A-Za-z0-9_]+)+)/', (string) file_get_contents($file), $m);
                    foreach (array_unique($m[1]) as $class) {
                        foreach ($namespaces as $prefix => [$owner, $ownerDir]) {
                            if (!str_starts_with($class . '\\', $prefix)) {
                                continue;
                            }
                            if ($owner === $name) {
                                break;
                            }
                            $since = self::classSince($ownerDir . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php');
                            if ($since === null) {
                                break;
                            }
                            $checked++;
                            $require = $composer['require'][$owner] ?? null;
                            $conflict = $composer['conflict'][$owner] ?? null;
                            $requireOk = $require === null
                                || (preg_match('/\^(\d+\.\d+(?:\.\d+)?)/', (string) $require, $r) === 1 && version_compare($r[1], $since, '>='));
                            $conflictOk = $conflict !== null && version_compare(ltrim((string) $conflict, '<'), $since, '>=');
                            if (!$requireOk || !$conflictOk) {
                                $missing[] = sprintf(
                                    '%s names %s (@since %s): require %s, conflict %s',
                                    $name,
                                    $class,
                                    $since,
                                    $require ?? '-',
                                    $conflict ?? '-',
                                );
                            }
                            break;
                        }
                    }
                }
            }
        }

        $this->assertGreaterThan(50, $checked, 'the scan found @since classes named across packages');
        $this->assertSame([], array_values(array_unique($missing)), 'raise the require and conflict to the version the named class arrived in');
    }

    /** The @since version in a class-like declaration's docblock, or null. */
    private static function classSince(string $file): ?string
    {
        if (!is_file($file)) {
            return null;
        }
        $pattern = '/\/\*\*((?:(?!\*\/).)*)\*\/\s*(?:#\[[^\]]*\]\s*)*(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s/s';
        if (preg_match($pattern, (string) file_get_contents($file), $doc) !== 1) {
            return null;
        }

        return preg_match('/@since\s+(\d+\.\d+(?:\.\d+)?)/', $doc[1], $since) === 1 ? $since[1] : null;
    }

    /** Starters and the skeleton are apps (they require released versions; the release checklist raises them). */
    private static function isStarter(array $composer): bool
    {
        return ($composer['type'] ?? 'library') === 'project';
    }

    /**
     * Each spelling the guard must catch, and lines it must leave alone (building a driver,
     * naming one in a comment, a capability check).
     */
    public function test_storage_driver_class_check_patterns(): void
    {
        $caught = [
            'instanceof' => 'if ($driver instanceof PdoDriver) {',
            'instanceof, fully qualified' => 'return $d instanceof \Preflow\Data\Driver\MysqlDriver;',
            'instanceof, Driver\\' => '$ok = $d instanceof Driver\SqliteDriver;',
            'parameter' => 'public function run(PdoDriver $driver): void',
            'nullable parameter' => 'function f(int $a, ?JsonFileDriver $d = null) {}',
            'parameter on its own line' => '        PdoDriver $driver,',
            'nullable parameter on its own line' => '        ?\Preflow\Data\Driver\MysqlDriver $driver = null,',
            'promoted parameter' => '        private readonly PdoDriver $driver,',
            'public promoted parameter' => 'public function __construct(public PdoDriver $d) {}',
            'promoted nullable parameter' => '        protected ?SqliteDriver $driver = null,',
            'union type' => 'function f(int|PdoDriver $d) {}',
            'union type, driver first' => 'function f(PdoDriver|string $d) {}',
            'intersection type' => 'function f(RunsSql&PdoDriver $d) {}',
            'by reference' => 'function f(PdoDriver &$d) {}',
            'variadic' => 'function f(PdoDriver ...$d) {}',
            'is_a' => 'if (is_a($driver, PdoDriver::class)) {',
            'is_a, fully qualified' => 'if (is_a($driver, \Preflow\Data\Driver\SqliteDriver::class, true)) {',
            'is_subclass_of' => 'if (is_subclass_of($driver, Driver\PdoDriver::class)) {',
            '::class ===' => 'if ($driver::class === MysqlDriver::class) {',
            '=== ::class' => 'if (MysqlDriver::class === $driver::class) {',
            '!== ::class' => 'if ($driver::class !== JsonFileDriver::class) {',
            'get_class ===' => 'if (get_class($driver) === SqliteDriver::class) {',
            'get_class ==' => 'if (get_class($driver) == SqliteDriver::class) {',
        ];
        foreach ($caught as $spelling => $line) {
            $this->assertTrue(self::checksDriverClass($line), "caught: {$spelling}: {$line}");
        }

        $allowed = [
            'building a driver' => '$x = new PdoDriver($pdo, $dialect);',
            'building one, fully qualified' => 'return new \Preflow\Data\Driver\MysqlDriver($pdo);',
            'a driver class in config' => "'driver' => \\Preflow\\Data\\Driver\\JsonFileDriver::class,",
            'a use statement' => 'use Preflow\Data\Driver\PdoDriver;',
            'a docblock' => '     * @param PdoDriver $driver the driver',
            'a nullable docblock type' => '     * @param ?PdoDriver $driver',
            'a line comment' => '        // $driver instanceof PdoDriver was the old check',
            'a hash comment' => '        # is_a($d, PdoDriver::class)',
            'a block comment line' => '/* function f(PdoDriver $d) */',
            'a capability check' => 'if ($driver instanceof RunsSql) {',
            'a longer class name' => 'function f(PdoDriverFactory $f) {}',
            'a capability parameter' => 'function f(PdoConnection $driver) {}',
            // preflow/testing builds its drivers and keeps them in typed properties
            'a property holding a built driver' => '    private ?SqliteDriver $sqliteDriver = null;',
            'a property without a default' => '    private MysqlDriver $driver;',
        ];
        foreach ($allowed as $spelling => $line) {
            $this->assertFalse(self::checksDriverClass($line), "not caught: {$spelling}: {$line}");
        }
    }

    /**
     * Whether a source line asks a built-in storage driver for its class: `instanceof`, a parameter
     * type (on any line, promoted or not, nullable, union or intersection, by reference, variadic),
     * `is_a()`, `is_subclass_of()`, or a `::class` / `get_class()` comparison. Comment lines,
     * `new …` and a property that holds a driver the class built (preflow/testing) are not checks.
     */
    private static function checksDriverClass(string $line): bool
    {
        $code = ltrim($line);
        if ($code === '' || str_starts_with($code, '*') || str_starts_with($code, '/*') || str_starts_with($code, '//')
            || (str_starts_with($code, '#') && !str_starts_with($code, '#['))) {
            return false;
        }
        $driver = '\\\\?' . self::DRIVERS;
        $patterns = [
            // instanceof
            '/\binstanceof\s+' . $driver . '\b/',
            // a parameter type: after "(", ",", "|", "&", "?", a modifier (promoted) or the start of the
            // line, before "$", "&$" or "...$"; not a property ("$name;" or "$name = …;")
            '/(?:^|[(,|&?]|\b(?:public|protected|private|readonly|var|static))\s*\??' . $driver
                . '\s*(?:[|&]\s*\??\\\\?[\w\\\\]+\s*)*(?:&\s*)?(?:\.\.\.\s*)?\$\w++(?!\s*(?:=[^,)]*)?;)/',
            // is_a() / is_subclass_of() against a driver class
            '/\b(?:is_a|is_subclass_of)\s*\(.*' . $driver . '::class\b/',
            // a ::class or get_class() comparison
            '/' . $driver . '::class\s*[!=]==?|[!=]==?\s*' . $driver . '::class\b/',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line) === 1) {
                return true;
            }
        }

        return false;
    }

    public function test_architecture_no_ambient_state(): void
    {
        /** @var array<string, array<string, string|array<string, string>>> $allow */
        $allow = require __DIR__ . '/ambient-state-allow.php';
        /** @var array<string, list<string>> $baseline */
        $baseline = require __DIR__ . '/ambient-state-baseline.php';

        $hits = [];
        $scanned = [];
        foreach (glob(self::packages() . '/*/composer.json') ?: [] as $composerFile) {
            $dir = dirname($composerFile);
            $composer = json_decode((string) file_get_contents($composerFile), true, flags: JSON_THROW_ON_ERROR);
            $roots = array_values((array) ($composer['autoload']['psr-4'] ?? []));
            $roots = $roots === [] ? ['src/'] : $roots;
            foreach ($roots as $root) {
                foreach ((array) $root as $path) {
                    $sourceDir = $dir . '/' . rtrim($path, '/');
                    if (!is_dir($sourceDir)) {
                        continue;
                    }
                    $scanned[basename($dir)] = true;
                    foreach ($this->files($sourceDir) as $file) {
                        if (!str_ends_with($file, '.php')) {
                            continue;
                        }
                        foreach (self::ambientStateHits($file) as [$lineNo, $text, $rules]) {
                            $hits[] = [$this->relative($file), $lineNo, $text, $rules];
                        }
                    }
                }
            }
        }

        $allowUsed = [];
        $baselineUsed = [];
        $unexpected = [];
        foreach ($hits as [$file, $lineNo, $text, $rules]) {
            $left = [];
            foreach ($rules as $rule) {
                $entry = self::allowedBy($allow, $file, $rule, $text);
                if ($entry === null) {
                    $left[] = $rule;
                } else {
                    $allowUsed[$entry] = true;
                }
            }
            if ($left === []) {
                continue;
            }
            $key = $file . ': ' . $text;
            $listed = count(array_keys($baseline[$file] ?? [], $text, true));
            if (($baselineUsed[$key] ?? 0) < $listed) {
                $baselineUsed[$key] = ($baselineUsed[$key] ?? 0) + 1;
                continue;
            }
            $unexpected[] = sprintf('%s:%d [%s] %s', $file, $lineNo, implode(', ', $left), $text);
        }

        $stale = [];
        foreach ($baseline as $file => $lines) {
            foreach (array_count_values($lines) as $text => $listed) {
                $found = $baselineUsed[$file . ': ' . $text] ?? 0;
                if ($found < $listed) {
                    $stale[] = sprintf('%s: %s (listed %d, hits %d)', $file, $text, $listed, $found);
                }
            }
        }

        $unusedAllow = [];
        foreach ($allow as $pattern => $rules) {
            foreach ($rules as $rule => $reason) {
                foreach (is_array($reason) ? array_keys($reason) : [null] as $text) {
                    $entry = self::allowEntryKey($pattern, $rule, $text);
                    if (!isset($allowUsed[$entry])) {
                        $unusedAllow[] = $entry;
                    }
                }
            }
        }

        foreach (['core', 'auth', 'folio', 'notify'] as $package) {
            $this->assertArrayHasKey($package, $scanned, 'a scanned package');
        }
        $this->assertSame([], $unexpected, 'ambient state: read it from Contexts::current() (see the class docblock); a permanent exception goes to ambient-state-allow.php with its reason');
        $this->assertSame([], $stale, 'these baseline entries no longer hit: remove them from the baseline (tools/tests/ambient-state-baseline.php)');
        $this->assertSame([], $unusedAllow, 'these allowlist entries match nothing: remove them from tools/tests/ambient-state-allow.php');
    }

    /**
     * One fixture file per rule, each with exactly one hit, and a clock stamp that is no hit; then
     * spellings each rule must catch and harmless lines it must leave alone.
     */
    public function test_architecture_no_ambient_state_catches_each_rule(): void
    {
        $hits = [];
        foreach ($this->files(__DIR__ . '/Fixtures/AmbientState') as $file) {
            foreach (self::ambientStateHits($file) as [, , $rules]) {
                foreach ($rules as $rule) {
                    $hits[] = basename($file) . ': ' . $rule;
                }
            }
        }
        sort($hits);
        $this->assertSame([
            'Clock.php: clock',
            'ContextDump.php: context-dump',
            'ContextProperty.php: context-property',
            'FrameContextWrite.php: frame-context-write',
            'Peek.php: peek',
            'RequestBinding.php: request-binding',
            'Setter.php: setter',
            'StaticState.php: static-state',
            'Superglobal.php: superglobal',
        ], $hits, 'each rule hits its fixture once; the stamp (ClockStamp.php) is no hit');
        $this->assertSame(array_keys(self::AMBIENT_RULES), [
            'superglobal', 'clock', 'setter', 'static-state', 'context-property', 'peek', 'context-dump', 'frame-context-write',
            'request-binding',
        ], 'every rule has a fixture');

        $caught = [
            'superglobal' => [
                '$token = $_COOKIE[\'t\'] ?? null;',
                '$request = $creator->fromGlobals();',
                'unset($_SESSION);',
            ],
            'clock' => [
                '$at = time() + 60;',
                '$at = \time();',
                '$start = microtime(true);',
                '$start = hrtime(true);',
                '$day = gmdate(\'Y-m-d\');',
                '$stamp = date(DATE_ATOM);',
                '$stamp = date(\DateTimeInterface::ATOM);',
                '$now = new \DateTimeImmutable();',
                '$now = new DateTime(\'now\');',
                '$old = new \DateTimeImmutable(\'-60 seconds\');',
                '$old = new \DateTimeImmutable(\'-\' . self::STALE_JOB_SECONDS . \' seconds\');',
                '$month = new \DateTimeImmutable(\'first day of this month 00:00:00\');',
                '$at = strtotime(\'+1 day\');',
            ],
            'setter' => [
                '$this->translator->setLocale($locale);',
                '$auth->setUser($user);',
                '$auth->addUser($guard, $user);',
                '$this->live->showWorkingCopies();',
                'ContentLocale::set($locale);',
                '$this->contentLocale->set($locale);',
            ],
            'static-state' => [
                'private static array $cache = [];',
                'public static ?Translator $translator = null;',
                'protected static $loaded;',
                'static $memo = null;',
            ],
            'context-property' => [
                'private RenderContext $context;',
                'public ?RenderContext $context = null;',
                '        private readonly RenderContext $ctx,',
                'public function __construct(private \Preflow\Core\Context\RenderContext $context) {}',
                'protected RenderContext|null $context;',
            ],
            'peek' => ['$ctx = Contexts::peek();'],
            'context-dump' => [
                'error_log(var_export($ctx, true));',
                '$fields = (array) $this->context;',
                '$fields = get_object_vars(Contexts::current());',
            ],
            'frame-context-write' => [
                '$frame->context = $narrowed;',
                'Contexts::frame()->context = $ctx;',
            ],
            'request-binding' => [
                '$container->instance(RequestContext::class, $context);',
                '$this->container->instance(\\Preflow\\Core\\Http\\CookieQueue::class, $cookieQueue);',
            ],
        ];
        foreach ($caught as $rule => $lines) {
            foreach ($lines as $line) {
                $this->assertContains($rule, self::ambientRulesOf($line), "caught by {$rule}: {$line}");
            }
        }

        $harmless = [
            '$at = $this->clock->time();',
            '$at = Clock::time();',
            '$changed = filemtime($file);',
            '$day = date(\'Y-m-d\', $timestamp);',
            '$at = new \DateTimeImmutable(\'@\' . $timestamp);',
            '$at = new \DateTimeImmutable($value);',
            '$at = new \DateTimeImmutable(\'2026-01-01\');',
            '$name = \'export-\' . date(\'Ymd\') . \'.zip\'; // clock: stamp',
            'public function time(): int',
            'public static function create(): static',
            'return new static($value);',
            'static::$cache[$key] = $value;',
            '$sort = static fn (array $a): int => 0;',
            'usort($list, static function ($a, $b) {',
            '     * @var static $instance',
            '        // time() is read once, at the edge',
            'public function render(RenderContext $ctx): string',
            'function f(?RenderContext $ctx = null) {}',
            '$this->context = $context;',
            '$same = $frame->context === $ctx;',
            '$code = var_export($value, true);',
            '$options = (array) $options;',
            '$path = $_ENV[\'DATA_PATH\'] ?? null;',
            '$locale = $this->translator->getLocale();',
            '$ctx = Contexts::current();',
            '$preferences->setLocaleFor($user, $locale);',
            '$this->overrides->set($locale, $key, $text, $by);',
            '$context = $container->get(RequestContext::class);',
        ];
        foreach ($harmless as $line) {
            $this->assertSame([], self::ambientRulesOf($line), "harmless: {$line}");
        }
    }

    /** @return list<array{int, string, list<string>}> each hit in $file: line number, trimmed text, rules */
    private static function ambientStateHits(string $file): array
    {
        $hits = [];
        foreach (file($file) ?: [] as $n => $line) {
            $rules = self::ambientRulesOf($line);
            if ($rules !== []) {
                $hits[] = [$n + 1, trim($line), $rules];
            }
        }

        return $hits;
    }

    /** @return list<string> the AMBIENT_RULES that $line hits */
    private static function ambientRulesOf(string $line): array
    {
        $code = ltrim($line);
        if ($code === '' || str_starts_with($code, '*') || str_starts_with($code, '/*') || str_starts_with($code, '//')
            || (str_starts_with($code, '#') && !str_starts_with($code, '#['))) {
            return [];
        }
        $rules = [];
        foreach (self::AMBIENT_RULES as $rule => $pattern) {
            if (preg_match($pattern, $line) !== 1) {
                continue;
            }
            if ($rule === 'clock' && preg_match('~//\s*clock:\s*stamp\s*$~', $line) === 1) {
                continue;
            }
            $rules[] = $rule;
        }

        return $rules;
    }

    /**
     * The allowlist entry that permits $rule on this line of $file, or null.
     *
     * @param array<string, array<string, string|array<string, string>>> $allow
     */
    private static function allowedBy(array $allow, string $file, string $rule, string $text): ?string
    {
        foreach ($allow as $pattern => $rules) {
            if (!isset($rules[$rule]) || ($pattern !== $file && !fnmatch($pattern, $file, FNM_PATHNAME))) {
                continue;
            }
            if (is_string($rules[$rule])) {
                return self::allowEntryKey($pattern, $rule, null);
            }
            if (isset($rules[$rule][$text])) {
                return self::allowEntryKey($pattern, $rule, $text);
            }
        }

        return null;
    }

    private static function allowEntryKey(string $pattern, string $rule, ?string $text): string
    {
        return $pattern . ' [' . $rule . ']' . ($text === null ? '' : ': ' . $text);
    }

    /** @return list<string> every file below $dir */
    private function files(string $dir): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    private function relative(string $file): string
    {
        return substr($file, strlen(self::packages()) + 1);
    }

    private function relativeToRepo(string $file): string
    {
        return substr($file, strlen(dirname(__DIR__, 2)) + 1);
    }
}
