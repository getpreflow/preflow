<?php

declare(strict_types=1);

namespace Preflow\DevTools\Tests\Command;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Cache\CacheRegistry;
use Preflow\Core\Cache\FileCache;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\DevTools\Command\CacheClearCommand;
use Preflow\DevTools\Command\CacheListCommand;

final class CacheCommandsTest extends TestCase
{
    private string $dir;
    private bool $empty = false;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cache_cmd_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/storage/cache', 0777, true);
        file_put_contents($this->dir . '/storage/cache/one.txt', str_repeat('a', 1536));
        file_put_contents($this->dir . '/storage/cache/two.txt', 'bb');
        file_put_contents($this->dir . '/storage/cache/other.txt', 'keep');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/storage/cache/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir . '/storage/cache');
        @rmdir($this->dir . '/storage');
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            is_dir($f) ? null : @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function factory(): \Closure
    {
        $dir = $this->dir;
        $empty = $this->empty;

        return static function () use ($dir, $empty): Application {
            $app = Application::create($dir);
            $app->registerProvider(new class($dir, $empty) extends ServiceProvider {
                public function __construct(private readonly string $dir, private readonly bool $empty) {}
                public function register(Container $container): void {}
                public function boot(Container $container): void
                {
                    if ($this->empty) {
                        return;
                    }
                    $registry = $container->get(CacheRegistry::class);
                    $registry->register(new FileCache('one', 'Cache one', $this->dir . '/storage/cache/one.txt'));
                    $registry->register(new FileCache('two', 'Cache two', $this->dir . '/storage/cache/two.txt'));
                }
            });
            $app->boot();

            return $app;
        };
    }

    /** @return array{0:int,1:string} */
    private function exec(object $command, array $args = []): array
    {
        ob_start();
        $code = $command->execute($args);

        return [$code, (string) ob_get_clean()];
    }

    public function test_list_prints_header_and_rows(): void
    {
        [$code, $out] = $this->exec(new CacheListCommand($this->factory()));

        $this->assertSame(0, $code);
        $lines = explode("\n", trim($out));
        $this->assertMatchesRegularExpression('/^Key\s+Label\s+Entries\s+Size$/', $lines[0]);
        $this->assertMatchesRegularExpression('/^one\s+Cache one\s+1\s+1\.5 KB$/', $lines[1]);
        $this->assertMatchesRegularExpression('/^two\s+Cache two\s+1\s+2 B$/', $lines[2]);
    }

    public function test_list_with_empty_registry(): void
    {
        $this->empty = true;

        [$code, $out] = $this->exec(new CacheListCommand($this->factory()));

        $this->assertSame(0, $code);
        $this->assertSame("No caches registered.\n", $out);
    }

    public function test_clear_one(): void
    {
        [$code, $out] = $this->exec(new CacheClearCommand($this->factory()), ['one']);

        $this->assertSame(0, $code);
        $this->assertSame("Cleared one: 1 file(s), 1.5 KB.\n", $out);
        $this->assertFileDoesNotExist($this->dir . '/storage/cache/one.txt');
        $this->assertFileExists($this->dir . '/storage/cache/two.txt');
    }

    public function test_clear_unknown_key_fails_and_deletes_nothing(): void
    {
        [$code, $out] = $this->exec(new CacheClearCommand($this->factory()), ['twig']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Unknown cache: twig (known: one, two)', $out);
        $this->assertFileExists($this->dir . '/storage/cache/one.txt');
        $this->assertFileExists($this->dir . '/storage/cache/two.txt');
    }

    public function test_clear_all(): void
    {
        [$code, $out] = $this->exec(new CacheClearCommand($this->factory()), ['--all']);

        $this->assertSame(0, $code);
        $this->assertSame("Cleared one: 1 file(s), 1.5 KB.\nCleared two: 1 file(s), 2 B.\n", $out);
        $this->assertFileDoesNotExist($this->dir . '/storage/cache/one.txt');
        $this->assertFileDoesNotExist($this->dir . '/storage/cache/two.txt');
    }

    public function test_clear_without_args_prints_note_and_keeps_unregistered_files(): void
    {
        [$code, $out] = $this->exec(new CacheClearCommand($this->factory()));

        $this->assertSame(0, $code);
        $this->assertStringStartsWith(
            "Note: use \"cache:clear --all\"; the bare form will require it in a later release.\n",
            $out,
        );
        $this->assertStringContainsString('Cleared one: 1 file(s), 1.5 KB.', $out);
        $this->assertStringContainsString('Cleared two: 1 file(s), 2 B.', $out);
        $this->assertFileDoesNotExist($this->dir . '/storage/cache/one.txt');
        $this->assertFileDoesNotExist($this->dir . '/storage/cache/two.txt');
        $this->assertFileExists($this->dir . '/storage/cache/other.txt');
    }
}
