<?php

declare(strict_types=1);

namespace Preflow\Tools\Tests;

use PHPUnit\Framework\TestCase;

/**
 * testing.isolated-tests-check-frame-balance: every test class that runs tests in a separate
 * process (#[RunInSeparateProcess], #[RunTestsInSeparateProcesses], or the doc-comment forms) uses
 * Preflow\Testing\ChecksFrameBalance, directly or through its parent class. FrameBalanceExtension
 * never runs in a child process, so without the trait a test that leaves a render frame open
 * would pass. The contracts (RenderIsolationContract, HypermediaDriverContract) use it, so their
 * runners count. Scanned: packages/{pkg}/tests, tools/tests, examples/{app}/tests.
 *
 * ALLOWED lists files that may not use it, each with its reason; an entry that no longer matches
 * an isolated class without the trait fails too, so the list only shrinks.
 */
final class FrameBalanceGuardTest extends TestCase
{
    /** @var array<string, string> file (relative to the repository) => why it does not use the trait */
    private const array ALLOWED = [
        'packages/notify/tests/NotifyWithoutCoreTest.php' => 'notify\'s suite runs without core and preflow/testing (its split repository), and this test checks that no core class loads',
    ];

    /** A trait use in a class body naming ChecksFrameBalance, alone or in a list (`use A, ChecksFrameBalance;`, `use ChecksFrameBalance, B { … }`). */
    private const string USES = '/^\s*use\s+(?:\\\\?[\w\\\\]+\s*,\s*)*(?:\\\\?Preflow\\\\Testing\\\\)?ChecksFrameBalance\s*(?:,\s*\\\\?[\w\\\\]+\s*)*[;{]/m';

    private const string ISOLATED = '/#\[\\\\?(?:PHPUnit\\\\Framework\\\\Attributes\\\\)?RunInSeparateProcess\]|#\[\\\\?(?:PHPUnit\\\\Framework\\\\Attributes\\\\)?RunTestsInSeparateProcesses\]|@runInSeparateProcess\b|@runTestsInSeparateProcesses\b/';

    public function test_isolated_test_classes_check_the_frame_balance(): void
    {
        $root = dirname(__DIR__, 2);
        $classes = [];
        foreach ($this->files($root) as $file) {
            foreach (self::classes((string) file_get_contents($file)) as $class) {
                $classes[$class['name']][] = $class + ['file' => substr($file, strlen($root) + 1)];
            }
        }

        $missing = [];
        $allowedHits = [];
        foreach ($classes as $declared) {
            foreach ($declared as $class) {
                // Only test classes (a class with a parent); a helper that mentions the attributes in its text is none.
                if (!$class['isolated'] || $class['parent'] === null || self::checks($class, $classes)) {
                    continue;
                }
                if (isset(self::ALLOWED[$class['file']])) {
                    $allowedHits[$class['file']] = true;
                    continue;
                }
                $missing[] = "{$class['file']}: {$class['name']}";
            }
        }

        $this->assertSame([], $missing, "These test classes run tests in a separate process but do not use Preflow\\Testing\\ChecksFrameBalance:\n" . implode("\n", $missing));
        $stale = array_values(array_diff(array_keys(self::ALLOWED), array_keys($allowedHits)));
        $this->assertSame([], $stale, 'ALLOWED entries that no longer match: remove them');
    }

    /** The trait counts alone, first, last or in the middle of a list of traits, and with a conflict block. */
    public function test_the_trait_counts_in_a_list_of_traits(): void
    {
        $uses = [];
        foreach ([
            'use ChecksFrameBalance;',
            'use \\Preflow\\Testing\\ChecksFrameBalance;',
            'use PublishingScaffold, ChecksFrameBalance;',
            'use ChecksFrameBalance, PublishingScaffold;',
            'use A, \\Preflow\\Testing\\ChecksFrameBalance, B;',
            'use ChecksFrameBalance, A { A::x insteadof B; }',
            'use PublishingScaffold;',
            'use NotChecksFrameBalance;',
            'use ChecksFrameBalanceToo;',
        ] as $line) {
            $classes = self::classes("<?php\nfinal class T extends TestCase\n{\n    {$line}\n}\n");
            $uses[$line] = $classes[0]['uses'];
        }

        $this->assertSame([true, true, true, true, true, true, false, false, false], array_values($uses), (string) json_encode($uses));
    }

    /** @return list<string> */
    private function files(string $root): array
    {
        $files = [];
        foreach ([...glob($root . '/packages/*/tests', GLOB_ONLYDIR) ?: [], $root . '/tools/tests', ...glob($root . '/examples/*/tests', GLOB_ONLYDIR) ?: []] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                // This guard names the attributes in its own text.
                if ($f->getExtension() === 'php' && $f->getPathname() !== __FILE__) {
                    $files[] = $f->getPathname();
                }
            }
        }
        sort($files);

        return $files;
    }

    /**
     * The classes a file declares: name, parent (short name), whether it runs tests isolated and
     * whether it uses the trait itself.
     *
     * @return list<array{name: string, parent: ?string, isolated: bool, uses: bool}>
     */
    private static function classes(string $source): array
    {
        $source = self::withoutStrings($source);
        preg_match_all('/^((?:#\[[^\n]*\]\n|\/\*\*(?:(?!\*\/).)*\*\/\n)*)(?:(?:final|abstract|readonly) )*class (\w+)([^{]*)\{\n/ms', $source, $m, PREG_OFFSET_CAPTURE);
        $classes = [];
        foreach ($m[0] as $i => [$whole, $offset]) {
            $start = $offset + strlen($whole);
            $end = isset($m[0][$i + 1]) ? $m[0][$i + 1][1] : strlen($source);
            $body = substr($source, $start, $end - $start);
            $head = $m[1][$i][0];
            $parent = preg_match('/extends\s+\\\\?(?:[\w\\\\]*\\\\)?(\w+)/', $m[3][$i][0], $p) === 1 ? $p[1] : null;
            $classes[] = [
                'name' => $m[2][$i][0],
                'parent' => $parent,
                'isolated' => preg_match(self::ISOLATED, $head . $body) === 1,
                'uses' => preg_match(self::USES, $body) === 1,
            ];
        }

        return $classes;
    }

    /** $source with the contents of its strings (heredocs too) blanked: a class written into a file is no class of the test. */
    private static function withoutStrings(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_ENCAPSED_AND_WHITESPACE, T_CONSTANT_ENCAPSED_STRING], true)) {
                $out .= preg_replace('/[^\n]/', ' ', $token[1]);
            } else {
                $out .= is_array($token) ? $token[1] : $token;
            }
        }

        return $out;
    }

    /**
     * @param array{name: string, parent: ?string, uses: bool} $class
     * @param array<string, list<array{name: string, parent: ?string, uses: bool}>> $all by short name
     */
    private static function checks(array $class, array $all, int $depth = 0): bool
    {
        if ($class['uses'] || in_array($class['parent'], ['RenderIsolationContract', 'HypermediaDriverContract'], true)) {
            return true;
        }
        if ($class['parent'] === null || $depth > 10 || !isset($all[$class['parent']])) {
            return false;
        }
        foreach ($all[$class['parent']] as $parent) {
            if (self::checks($parent, $all, $depth + 1)) {
                return true;
            }
        }

        return false;
    }
}
