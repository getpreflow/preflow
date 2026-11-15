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
