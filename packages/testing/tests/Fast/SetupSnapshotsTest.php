<?php

declare(strict_types=1);

namespace Preflow\Testing\Tests\Fast;

use PHPUnit\Framework\TestCase;
use Preflow\Testing\Fast\SetupSnapshots;

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
        putenv('PREFLOW_SNAPSHOT_PROBE');
        putenv('PREFLOW_TEST_SNAPSHOTS');
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

    private static function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($rii as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
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
        $dir = $run(['PREFLOW_TEST_RUN' => $inherited] + $env);
        $this->dirs[] = $dir;
        $this->assertSame(sys_get_temp_dir() . '/preflow_snapshots_' . $inherited, $dir);
        $this->assertFileExists($dir . '/x.ser');
    }
}
