<?php

declare(strict_types=1);

namespace Preflow\DevTools\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The preflow binary must load the project's autoloader, not the framework's
 * own, also when the packages are symlinked path installs (the bin's __DIR__
 * then lies inside the framework checkout).
 */
final class BinAutoloadTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/bin_autoload_' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
    }

    public function test_bin_prefers_composer_autoload_path(): void
    {
        $this->makeProject($this->root . '/project', true);
        $this->makeProject($this->root . '/cwd', false); // the cwd lists the command, but its own autoloader lacks it
        $prepend = $this->root . '/prepend.php';
        file_put_contents($prepend, "<?php \$GLOBALS['_composer_autoload_path'] = " . var_export($this->root . '/project/vendor/autoload.php', true) . ';');

        [$out, $err] = $this->runBin($this->root . '/cwd', ['-d', 'auto_prepend_file=' . $prepend]);

        $this->assertStringContainsString('marker:run', $out);
        $this->assertStringNotContainsString('not a command class', $err);
    }

    public function test_bin_prefers_project_autoloader_over_monorepo(): void
    {
        $this->makeProject($this->root . '/project', true);

        [$out, $err] = $this->runBin($this->root . '/project');

        $this->assertStringContainsString('marker:run', $out);
        $this->assertStringNotContainsString('not a command class', $err);
    }

    public function test_bin_fails_clearly_without_any_autoloader(): void
    {
        // Not possible to hide the monorepo autoloader from the real bin, so copy the bin out of the tree.
        mkdir($this->root . '/lonely/bin', 0777, true);
        copy($this->bin(), $this->root . '/lonely/bin/preflow');
        mkdir($this->root . '/empty');

        [$out, $err, $code] = $this->runBin($this->root . '/empty', [], $this->root . '/lonely/bin/preflow');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('autoload', $err);
    }

    public function test_bin_exits_with_command_code(): void
    {
        $this->makeProject($this->root . '/project', true);

        [, , $code] = $this->runBin($this->root . '/project', [], null, 'marker:fail');

        $this->assertSame(3, $code);
    }

    public function test_unknown_command_exits_1(): void
    {
        $this->makeProject($this->root . '/project', true);

        [, , $code] = $this->runBin($this->root . '/project', [], null, 'no:such');

        $this->assertSame(1, $code);
    }

    private function bin(): string
    {
        return dirname(__DIR__) . '/bin/preflow';
    }

    /** A project whose autoloader loads the framework's and, when $withCommand, defines App\MarkerCommand and App\FailCommand (exits 3). */
    private function makeProject(string $dir, bool $withCommand): void
    {
        mkdir($dir . '/vendor', 0777, true);
        mkdir($dir . '/config', 0777, true);
        $monorepo = dirname(__DIR__, 3) . '/vendor/autoload.php';
        $autoload = "<?php\nnamespace {\nrequire " . var_export($monorepo, true) . ";\n}\n";
        if ($withCommand) {
            $autoload .= <<<'PHP'
namespace App {
    final class MarkerCommand implements \Preflow\DevTools\Command\CommandInterface
    {
        public function getName(): string { return 'marker:run'; }
        public function getDescription(): string { return 'marker'; }
        public function execute(array $args): int { return 0; }
    }

    final class FailCommand implements \Preflow\DevTools\Command\CommandInterface
    {
        public function getName(): string { return 'marker:fail'; }
        public function getDescription(): string { return 'fails'; }
        public function execute(array $args): int { return 3; }
    }
}
PHP;
        }
        file_put_contents($dir . '/config/commands.php', "<?php return ['App\\\\MarkerCommand', 'App\\\\FailCommand'];");
        file_put_contents($dir . '/vendor/autoload.php', $autoload);
    }

    /**
     * @param string[] $phpArgs
     * @return array{0: string, 1: string, 2: int}
     */
    private function runBin(string $cwd, array $phpArgs = [], ?string $bin = null, string $command = 'help'): array
    {
        $proc = proc_open(
            [PHP_BINARY, ...$phpArgs, $bin ?? $this->bin(), $command],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
        );
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [$out, $err, proc_close($proc)];
    }

    private function removeDir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            is_dir("$dir/$f") ? $this->removeDir("$dir/$f") : unlink("$dir/$f");
        }
        rmdir($dir);
    }
}
