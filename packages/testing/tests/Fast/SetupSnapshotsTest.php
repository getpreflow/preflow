<?php

declare(strict_types=1);

namespace Preflow\Testing\Tests\Fast;

use PHPUnit\Framework\TestCase;
use Preflow\Testing\Fast\SetupSnapshots;
use Preflow\Testing\Fast\SnapshotDatabase;

/**
 * A restored step leaves exactly what the real step left, whatever the test before changed;
 * a step runs for real when anything it could depend on (environment, files) differs.
 */
final class SetupSnapshotsTest extends TestCase
{
    private string $run = '';

    private string $snapshotDir = '';

    /** @var list<string> */
    private array $dirs = [];

    private int $runs = 0;

    protected function setUp(): void
    {
        $this->run = 'test-' . bin2hex(random_bytes(4));
        $this->snapshotDir = $this->tempDir();
    }

    protected function tearDown(): void
    {
        foreach (['PREFLOW_SNAPSHOT_PROBE', 'PREFLOW_TEST_SNAPSHOTS', 'PREFLOW_SNAPSHOT_SET', 'PREFLOW_SNAPSHOT_GONE'] as $var) {
            putenv($var);
            unset($_ENV[$var]);
        }
        foreach ($this->dirs as $dir) {
            self::remove($dir);
        }
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/preflow_snapshot_test_' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);

        return $this->dirs[] = $dir;
    }

    private function snapshots(string $root): SetupSnapshots
    {
        return (new SetupSnapshots($this->run, $this->snapshotDir))->root('{root}', $root);
    }

    private function probe(SetupSnapshots $snapshots, string $root): mixed
    {
        return $snapshots->step('probe-' . $this->name(), function () use ($root): string {
            $this->runs++;
            if (is_file($root . '/old.txt')) {
                unlink($root . '/old.txt');
            }
            @mkdir($root . '/data');
            file_put_contents($root . '/data/made.txt', 'written by the step');

            return 'made';
        });
    }

    /** @return array<string, string> relative path => content */
    private static function files(string $root): array
    {
        $files = [];
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($rii as $f) {
            $files[substr($f->getPathname(), strlen($root))] = $f->isDir() ? '/' : (string) file_get_contents($f->getPathname());
        }
        ksort($files);

        return $files;
    }

    /** Only ever this test's own temp directories (preflow_snapshot_test_*, preflow_snapshots_*). */
    private static function remove(string $dir): void
    {
        if ($dir === '' || !str_starts_with($dir, sys_get_temp_dir() . '/preflow_snapshot') || str_contains($dir, '..')) {
            return;
        }
        if (is_link($dir)) {
            unlink($dir);

            return;
        }
        if (!is_dir($dir)) {
            return;
        }
        chmod($dir, 0777);
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($rii as $f) {
            if ($f->isDir() && !$f->isLink()) {
                chmod($f->getPathname(), 0777);
            }
        }
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($rii as $f) {
            $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }

    public function test_a_restored_step_leaves_what_the_real_step_left(): void
    {
        // A file the step removes: the restore must remove it too.
        $first = $this->tempDir();
        file_put_contents($first . '/old.txt', 'before the step');
        $this->assertSame('made', $this->probe($this->snapshots($first), $first));
        $real = self::files($first);

        // What a test might leave behind: another file, a changed file.
        file_put_contents($first . '/data/leftover.txt', 'x');
        file_put_contents($first . '/data/made.txt', 'changed by the test');

        // The next test: another root, equal content before the step.
        $second = $this->tempDir();
        file_put_contents($second . '/old.txt', 'before the step');
        $this->assertSame('made', $this->probe($this->snapshots($second), $second));
        $this->assertSame(1, $this->runs, 'the second time the step is restored');
        $this->assertSame($real, self::files($second));
    }

    public function test_roots_with_other_names_but_equal_content_hit(): void
    {
        $first = $this->tempDir();
        $second = $this->tempDir();
        $this->probe($this->snapshots($first), $first);
        $this->probe($this->snapshots($second), $second);

        $this->assertSame(1, $this->runs);
    }

    public function test_another_environment_runs_the_step(): void
    {
        $first = $this->tempDir();
        $this->probe($this->snapshots($first), $first);

        $second = $this->tempDir();
        putenv('PREFLOW_SNAPSHOT_PROBE=1');
        $this->probe($this->snapshots($second), $second);

        $this->assertSame(2, $this->runs);
    }

    public function test_another_file_runs_the_step(): void
    {
        $first = $this->tempDir();
        $this->probe($this->snapshots($first), $first);

        $second = $this->tempDir();
        file_put_contents($second . '/earlier.txt', 'x');
        $this->probe($this->snapshots($second), $second);

        $this->assertSame(2, $this->runs);
    }

    public function test_an_ignored_file_does_not_count(): void
    {
        $first = $this->tempDir();
        file_put_contents($first . '/error.log', 'one notice');
        $this->probe($this->snapshots($first)->ignore('{root}/error.log'), $first);

        $second = $this->tempDir();
        file_put_contents($second . '/error.log', 'another notice');
        $this->probe($this->snapshots($second)->ignore('{root}/error.log'), $second);

        $this->assertSame(1, $this->runs);
        $this->assertSame('another notice', file_get_contents($second . '/error.log'), 'restore keeps the ignored file');
        $this->assertSame('written by the step', file_get_contents($second . '/data/made.txt'));
    }

    public function test_on_restore_is_called_on_a_hit_only(): void
    {
        $calls = 0;
        $onRestore = function () use (&$calls): void {
            $calls++;
        };

        $first = $this->tempDir();
        $this->probe($this->snapshots($first)->onRestore($onRestore), $first);
        $this->assertSame(0, $calls);

        $second = $this->tempDir();
        $this->probe($this->snapshots($second)->onRestore($onRestore), $second);
        $this->assertSame(1, $calls);
    }

    public function test_a_failing_step_is_not_stored(): void
    {
        $root = $this->tempDir();
        $step = function (): never {
            $this->runs++;
            throw new \RuntimeException('step failed');
        };

        try {
            $this->snapshots($root)->step('failing', $step);
            $this->fail('the step throws');
        } catch (\RuntimeException) {
        }

        $this->expectException(\RuntimeException::class);
        try {
            $this->snapshots($root)->step('failing', $step);
        } finally {
            $this->assertSame(2, $this->runs);
        }
    }

    public function test_every_hit_gets_its_own_copy_of_the_result(): void
    {
        $step = function (): \ArrayObject {
            $this->runs++;

            return new \ArrayObject(['seeded' => 1]);
        };

        $miss = $this->snapshots($this->tempDir())->step('object', $step);
        $miss['changed by the test'] = true;

        $first = $this->snapshots($this->tempDir())->step('object', $step);
        $this->assertNotSame($miss, $first);
        $this->assertSame(['seeded' => 1], $first->getArrayCopy());
        $first['changed by the test'] = true;

        $second = $this->snapshots($this->tempDir())->step('object', $step);
        $this->assertNotSame($first, $second);
        $this->assertSame(['seeded' => 1], $second->getArrayCopy());
        $this->assertSame(1, $this->runs);
    }

    public function test_an_unserializable_result_runs_every_time(): void
    {
        $root = $this->tempDir();
        $step = function (): \Closure {
            $this->runs++;

            return fn () => 1;
        };

        $this->assertInstanceOf(\Closure::class, $this->snapshots($root)->step('closure', $step));
        $this->assertInstanceOf(\Closure::class, $this->snapshots($root)->step('closure', $step));
        $this->assertSame(2, $this->runs);
    }

    public function test_an_outcome_naming_the_real_root_is_not_stored(): void
    {
        $step = function (string $root): string {
            $this->runs++;
            file_put_contents($root . '/where.txt', $root);

            return 'named';
        };

        $first = $this->tempDir();
        $this->snapshots($first)->step('names-root', fn () => $step($first));
        $second = $this->tempDir();
        $this->snapshots($second)->step('names-root', fn () => $step($second));

        $this->assertSame(2, $this->runs);
    }

    public function test_an_outcome_naming_a_path_beside_the_root_is_not_stored(): void
    {
        $step = function (string $root, string $how): string {
            $this->runs++;
            if ($how === 'file') {
                file_put_contents($root . '/where.txt', 'lock=' . $root . '.lock');
            } else {
                putenv('PREFLOW_SNAPSHOT_SET=' . $root . '.lock');
                $_ENV['PREFLOW_SNAPSHOT_SET'] = $root . '-cache';
            }

            return 'named';
        };
        foreach (['file', 'env'] as $i => $how) {
            $first = $this->tempDir();
            $this->snapshots($first)->step('beside-' . $how, fn () => $step($first, $how));
            putenv('PREFLOW_SNAPSHOT_SET');
            unset($_ENV['PREFLOW_SNAPSHOT_SET']);

            $second = $this->tempDir();
            $this->snapshots($second)->step('beside-' . $how, fn () => $step($second, $how));
            $this->assertSame(2 * ($i + 1), $this->runs, "{$how}: the second test runs the step for real");
            if ($how === 'file') {
                $this->assertSame('lock=' . $second . '.lock', file_get_contents($second . '/where.txt'));
            } else {
                $this->assertSame($second . '.lock', getenv('PREFLOW_SNAPSHOT_SET'));
                $this->assertSame($second . '-cache', $_ENV['PREFLOW_SNAPSHOT_SET']);
            }
            putenv('PREFLOW_SNAPSHOT_SET');
            unset($_ENV['PREFLOW_SNAPSHOT_SET']);
        }
    }

    public function test_off_without_run_or_with_the_switch(): void
    {
        $root = $this->tempDir();
        $step = function (): string {
            $this->runs++;

            return 'made';
        };

        $noRun = (new SetupSnapshots(run: ''))->root('{root}', $root);
        $this->assertFalse($noRun->enabled());
        $noRun->step('off', $step);
        $noRun->step('off', $step);
        $this->assertSame(2, $this->runs);

        putenv('PREFLOW_TEST_SNAPSHOTS=0');
        $switchedOff = $this->snapshots($root);
        $this->assertFalse($switchedOff->enabled());
        $switchedOff->step('off', $step);
        $switchedOff->step('off', $step);
        $this->assertSame(4, $this->runs);

        putenv('PREFLOW_TEST_SNAPSHOTS');
        $this->assertTrue($this->snapshots($root)->enabled());
    }

    public function test_the_snapshot_is_read_by_another_process(): void
    {
        $first = $this->tempDir();
        $this->probe($this->snapshots($first), $first);

        $second = $this->tempDir();
        $code = sprintf(
            <<<'PHP'
                require %s;
                $_ENV = unserialize(%s);
                $ran = false;
                $result = (new Preflow\Testing\Fast\SetupSnapshots(%s, %s))
                    ->root('{root}', %s)
                    ->step(%s, function () use (&$ran): string {
                        $ran = true;

                        return 'probe ran';
                    });
                echo $result, $ran ? ' probe ran' : ' probe not run';
                PHP,
            var_export(dirname(__DIR__, 4) . '/vendor/autoload.php', true),
            var_export(serialize($_ENV), true),
            var_export($this->run, true),
            var_export($this->snapshotDir, true),
            var_export($second, true),
            var_export('probe-' . $this->name(), true),
        );
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, getenv());
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        proc_close($process);

        $this->assertSame('made probe not run', $out, $err);
        $this->assertSame('written by the step', file_get_contents($second . '/data/made.txt'));
    }

    public function test_bootstrap_sets_the_run_and_its_process_removes_the_snapshots(): void
    {
        $code = sprintf(
            <<<'PHP'
                require %s;
                Preflow\Testing\Fast\SetupSnapshots::bootstrap();
                $run = (string) getenv('PREFLOW_TEST_RUN');
                $dir = sys_get_temp_dir() . '/preflow_snapshots_' . $run;
                mkdir($dir);
                file_put_contents($dir . '/x.ser', 'x');
                echo $dir;
                PHP,
            var_export(dirname(__DIR__, 4) . '/vendor/autoload.php', true),
        );
        $env = getenv();
        unset($env['PREFLOW_TEST_RUN']);
        $run = static function (array $env) use ($code): string {
            $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w']], $pipes, null, $env);
            $out = (string) stream_get_contents($pipes[1]);
            proc_close($process);

            return $out;
        };

        $dir = $run($env);
        $this->assertStringContainsString('/preflow_snapshots_', $dir);
        $this->assertDirectoryDoesNotExist($dir, 'the process that set the run removes its snapshots');

        // A process that inherits the run leaves the snapshots to the one that set it.
        $inherited = 'test-' . bin2hex(random_bytes(4));
        $expected = sys_get_temp_dir() . '/preflow_snapshots_' . $inherited;
        $this->dirs[] = $expected;
        $dir = $run(['PREFLOW_TEST_RUN' => $inherited] + $env);
        $this->assertSame($expected, $dir);
        $this->assertFileExists($dir . '/x.ser');
    }

    public function test_a_root_given_as_a_closure_is_read_when_it_is_used(): void
    {
        // holz: the image directory is made lazily, after the snapshots were set up.
        $dir = '';
        $make = function () use (&$dir): SetupSnapshots {
            $dir = '';

            return (new SetupSnapshots($this->run, $this->snapshotDir))->root('{root}', function () use (&$dir): string {
                return $dir;
            });
        };

        $first = $make();
        $dir = $this->tempDir();
        file_put_contents($dir . '/old.txt', 'before the step');
        $this->probe($first, $dir);
        $real = self::files($dir);

        $second = $make();
        $dir = $this->tempDir();
        file_put_contents($dir . '/old.txt', 'before the step');
        $this->assertSame('made', $this->probe($second, $dir));
        $this->assertSame(1, $this->runs, 'equal content under the lazily made directory hits');
        $this->assertSame($real, self::files($dir));

        // The same closure returning '' means "no such root right now": another state.
        $third = $make();
        $this->probe($third, $this->tempDir());
        $this->assertSame(2, $this->runs);
    }

    public function test_a_closure_root_that_returns_empty_is_no_root(): void
    {
        $step = function (): string {
            $this->runs++;

            return 'made';
        };
        $none = fn (): SetupSnapshots => (new SetupSnapshots($this->run, $this->snapshotDir))->root('{root}', fn (): string => '');
        $this->assertSame('made', $none()->step('none', $step));
        $this->assertSame('made', $none()->step('none', $step));
        $this->assertSame(1, $this->runs);
    }

    public function test_a_failed_restore_throws_naming_the_path(): void
    {
        $prepare = function (string $root): void {
            mkdir($root . '/locked');
            file_put_contents($root . '/locked/keep.txt', 'x');
        };
        $step = function (string $root): string {
            $this->runs++;
            file_put_contents($root . '/made.txt', 'y');

            return 'made';
        };
        $first = $this->tempDir();
        $prepare($first);
        $this->snapshots($first)->step('locked', fn () => $step($first));

        // Same content, but the directory can't be changed (mode isn't part of the state).
        $second = $this->tempDir();
        $prepare($second);
        chmod($second . '/locked', 0555);
        try {
            $this->snapshots($second)->step('locked', fn () => $step($second));
            $this->fail('a restore that cannot remove a file throws');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($second . '/locked/keep.txt', $e->getMessage());
        } finally {
            chmod($second . '/locked', 0777);
        }
        $this->assertSame(1, $this->runs);
    }

    public function test_a_symlink_under_a_root_is_refused(): void
    {
        $root = $this->tempDir();
        $outside = $this->tempDir();
        file_put_contents($outside . '/secret.txt', 'outside the root');
        symlink($outside, $root . '/link');

        try {
            $this->probe($this->snapshots($root), $root);
            $this->fail('a symlink under a root throws');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('symlink', $e->getMessage());
            $this->assertStringContainsString($root . '/link', $e->getMessage());
        }
        $this->assertSame(0, $this->runs);
        $this->assertSame('outside the root', file_get_contents($outside . '/secret.txt'), 'never followed');
    }

    public function test_an_ignored_symlink_is_kept_and_never_followed(): void
    {
        // A test copy of a project links to the project's vendor/ (Tests\Support\StarterApp).
        $vendor = $this->tempDir();
        file_put_contents($vendor . '/installed.json', 'the project\'s packages');
        $first = $this->tempDir();
        symlink($vendor, $first . '/vendor');
        $this->probe($this->snapshots($first)->ignore('{root}/vendor'), $first);

        $second = $this->tempDir();
        symlink($vendor, $second . '/vendor');
        $this->probe($this->snapshots($second)->ignore('{root}/vendor'), $second);

        $this->assertSame(1, $this->runs, 'the link is not part of the state');
        $this->assertTrue(is_link($second . '/vendor'), 'restore keeps the link');
        $this->assertSame(['installed.json'], array_values(array_diff(scandir($vendor) ?: [], ['.', '..'])), 'nothing written or removed behind the link');
        $this->assertSame('written by the step', file_get_contents($second . '/data/made.txt'));
    }

    public function test_environment_changes_made_by_the_step_are_restored(): void
    {
        $step = function (string $root): string {
            $this->runs++;
            putenv('PREFLOW_SNAPSHOT_SET=' . $root . '/media');
            $_ENV['PREFLOW_SNAPSHOT_SET'] = $root . '/media';
            putenv('PREFLOW_SNAPSHOT_GONE');
            unset($_ENV['PREFLOW_SNAPSHOT_GONE']);

            return 'made';
        };
        $before = function (): void {
            putenv('PREFLOW_SNAPSHOT_SET');
            unset($_ENV['PREFLOW_SNAPSHOT_SET']);
            putenv('PREFLOW_SNAPSHOT_GONE=1');
            $_ENV['PREFLOW_SNAPSHOT_GONE'] = '1';
        };

        $before();
        $first = $this->tempDir();
        $this->snapshots($first)->step('env', fn () => $step($first));

        $before();
        $second = $this->tempDir();
        $seen = null;
        $result = $this->snapshots($second)
            ->onRestore(function () use (&$seen): void {
                $seen = getenv('PREFLOW_SNAPSHOT_SET');
            })
            ->step('env', fn () => $step($second));

        $this->assertSame('made', $result);
        $this->assertSame(1, $this->runs, 'restored');
        $this->assertSame($second . '/media', getenv('PREFLOW_SNAPSHOT_SET'), 'the placeholder becomes the current root');
        $this->assertSame($second . '/media', $_ENV['PREFLOW_SNAPSHOT_SET'] ?? null);
        $this->assertFalse(getenv('PREFLOW_SNAPSHOT_GONE'), 'a variable the step removed is removed');
        $this->assertArrayNotHasKey('PREFLOW_SNAPSHOT_GONE', $_ENV);
        $this->assertSame($second . '/media', $seen, 'the environment is in place before onRestore');
    }

    public function test_only_a_paths_leading_placeholder_is_replaced(): void
    {
        $step = function (string $root): string {
            $this->runs++;
            mkdir($root . '/{root}');
            file_put_contents($root . '/{root}/{root}.txt', 'braces in a name');

            return 'made';
        };
        $first = $this->tempDir();
        $this->snapshots($first)->step('braces', fn () => $step($first));
        $real = self::files($first);

        $second = $this->tempDir();
        $this->snapshots($second)->step('braces', fn () => $step($second));
        $this->assertSame(1, $this->runs);
        $this->assertSame($real, self::files($second));
    }

    public function test_a_placeholder_must_look_like_braces_around_a_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SetupSnapshots($this->run, $this->snapshotDir))->root('root', $this->tempDir());
    }

    public function test_nested_roots_are_refused(): void
    {
        $outer = $this->tempDir();
        mkdir($outer . '/inner');
        $snapshots = $this->snapshots($outer)->root('{inner}', $outer . '/inner');

        $this->expectException(\LogicException::class);
        $this->probe($snapshots, $outer);
    }

    public function test_the_same_directory_twice_is_refused(): void
    {
        $root = $this->tempDir();
        $snapshots = $this->snapshots($root)->root('{again}', $root . '/');

        $this->expectException(\LogicException::class);
        $this->probe($snapshots, $root);
    }

    public function test_an_empty_root_directory_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SetupSnapshots($this->run, $this->snapshotDir))->root('{root}', '');
    }

    public function test_ignoring_a_path_under_no_registered_root_is_refused(): void
    {
        $root = $this->tempDir();
        $snapshots = (new SetupSnapshots($this->run, $this->snapshotDir))
            ->ignore('{later}/error.log')   // registered after: fine
            ->root('{root}', $root)
            ->root('{later}', $this->tempDir());
        $this->probe($snapshots, $root);
        $this->assertSame(1, $this->runs);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('{typo}/error.log');
        $this->probe($this->snapshots($root)->ignore('{typo}/error.log'), $root);
    }

    public function test_a_missing_root_and_an_empty_root_are_different_states(): void
    {
        $step = function (string $root): string {
            $this->runs++;
            is_dir($root) || mkdir($root);
            file_put_contents($root . '/made.txt', 'made');

            return 'made';
        };
        $missing = $this->tempDir();
        rmdir($missing);
        $this->snapshots($missing)->step('exists', fn () => $step($missing));

        $empty = $this->tempDir();
        $this->snapshots($empty)->step('exists', fn () => $step($empty));
        $this->assertSame(2, $this->runs, 'an empty root is not a missing root');
    }

    public function test_a_root_the_step_makes_or_removes_is_made_or_removed_on_restore(): void
    {
        $make = function (string $root): string {
            $this->runs++;
            mkdir($root);

            return 'made';
        };
        $first = $this->tempDir();
        rmdir($first);
        $this->snapshots($first)->step('makes-root', fn () => $make($first));
        $second = $this->tempDir();
        rmdir($second);
        $this->snapshots($second)->step('makes-root', fn () => $make($second));
        $this->assertSame(1, $this->runs);
        $this->assertDirectoryExists($second, 'an empty root the step made is made');

        $remove = function (string $root): string {
            $this->runs++;
            unlink($root . '/x.txt');
            rmdir($root);

            return 'removed';
        };
        $third = $this->tempDir();
        file_put_contents($third . '/x.txt', 'x');
        $this->snapshots($third)->step('removes-root', fn () => $remove($third));
        $fourth = $this->tempDir();
        file_put_contents($fourth . '/x.txt', 'x');
        $this->snapshots($fourth)->step('removes-root', fn () => $remove($fourth));
        $this->assertSame(2, $this->runs);
        $this->assertDirectoryDoesNotExist($fourth, 'a root the step removed is removed');
    }

    public function test_every_registered_database_is_in_the_key_and_restored(): void
    {
        $root = $this->tempDir();
        $a = new FakeSnapshotDatabase(['t' => 'a1']);
        $b = new FakeSnapshotDatabase(['u' => 'b1']);
        $make = fn (): SetupSnapshots => $this->snapshots($root)->database('a', $a)->database('b', $b);
        $step = function () use ($a, $b): string {
            $this->runs++;
            $a->tables['made'] = 'x';
            $b->tables['made'] = 'y';

            return 'done';
        };

        $make()->step('dbs', $step);
        $this->assertSame(1, $this->runs);
        $expectedA = $a->tables;
        $expectedB = $b->tables;

        // Same start: a hit, both restored with their own tables.
        $a->tables = ['t' => 'a1'];
        $b->tables = ['u' => 'b1'];
        $snapshots = $make();
        $this->assertSame('done', $snapshots->step('dbs', $step));
        $snapshots->flush();
        $this->assertSame(1, $this->runs);
        $this->assertSame($expectedA, $a->tables);
        $this->assertSame($expectedB, $b->tables);

        // Either database changed: the step runs for real.
        $a->tables = ['t' => 'changed'];
        $b->tables = ['u' => 'b1'];
        $make()->step('dbs', $step);
        $this->assertSame(2, $this->runs);

        $a->tables = ['t' => 'a1'];
        $b->tables = ['u' => 'changed'];
        $make()->step('dbs', $step);
        $this->assertSame(3, $this->runs);
    }

    public function test_a_hit_then_a_miss_writes_the_pending_tables_before_the_real_step(): void
    {
        $root = $this->tempDir();
        $db = new FakeSnapshotDatabase([]);
        $a = function () use ($db): string {
            $this->runs++;
            $db->tables['a'] = '1';

            return 'a';
        };
        $this->snapshots($root)->database('main', $db)->step('a', $a);
        $this->assertSame(1, $this->runs);

        // A fresh test: a hits (tables pending), then c has no snapshot for this state.
        $db->tables = [];
        $db->log = [];
        $seen = null;
        $c = function () use ($db, &$seen): string {
            $seen = $db->tables;
            $db->log[] = 'c';
            $db->tables['c'] = '3';

            return 'c';
        };
        $second = $this->snapshots($root)->database('main', $db);
        $this->assertSame('a', $second->step('a', $a));
        $this->assertSame([], $db->tables, 'a hit writes lazily');
        $this->assertSame('c', $second->step('c', $c));
        $this->assertSame(1, $this->runs);
        $this->assertSame(['restore', 'c'], $db->log, 'the pending tables are written before the real step');
        $this->assertSame(['a' => '1'], $seen);

        // c's outcome was captured from the restored state: a later test gets a and c.
        $db->tables = [];
        $third = $this->snapshots($root)->database('main', $db);
        $third->step('a', $a);
        $this->assertSame('c', $third->step('c', fn (): never => $this->fail('c is restored')));
        $third->flush();
        $this->assertSame(['a' => '1', 'c' => '3'], $db->tables);
    }
}

/** An in-memory database for SetupSnapshots tests: tables are name => content. */
final class FakeSnapshotDatabase implements SnapshotDatabase
{
    /** @var list<string> */
    public array $log = [];

    /** @param array<string, string> $tables */
    public function __construct(public array $tables = [])
    {
    }

    public function state(): array
    {
        return ['tables' => $this->tables];
    }

    public function capture(): array
    {
        return $this->tables;
    }

    public function restore(array $tables): void
    {
        $this->log[] = 'restore';
        $this->tables = $tables;
    }

    public function empty(): void
    {
        $this->log[] = 'empty';
        $this->tables = [];
    }
}
