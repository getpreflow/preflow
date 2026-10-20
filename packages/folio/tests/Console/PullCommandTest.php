<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Console;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Folio\Console\PullCommand;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\Folio\Transfer\CommandRunner;
use Preflow\Folio\Transfer\ContentSet;
use Preflow\Folio\Transfer\Exporter;

final class PullCommandTest extends TestCase
{
    use PublishingScaffold;

    /** @var resource */
    private $out;
    /** @var resource */
    private $err;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $cfg = (string) file_get_contents($this->dir . '/config/folio.php');
        file_put_contents($this->dir . '/config/folio.php', str_replace(
            "return ['path' => '/folio',",
            "return ['path' => '/folio', 'remotes' => ['production' => ['ssh' => 'user@host', 'port' => 2222, 'path' => '/srv/my site', 'php' => '/usr/bin/php84']],",
            $cfg,
        ));
        $this->working('post', 'p1', ['title' => ['en' => 'Live'], 'slug' => ['en' => 'live']]);
        $this->out = fopen('php://temp', 'w+b');
        $this->err = fopen('php://temp', 'w+b');
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    /** The "remote" content: an export of the current local state. */
    private function remoteExport(): string
    {
        $app = $this->app();
        $s = fopen('php://temp', 'w+b');
        (new Exporter(ContentSet::fromApp($app), $app->container()->get(DataManager::class)))->export($s);
        rewind($s);

        return (string) stream_get_contents($s);
    }

    private function runner(string $stdout, int $code = 0, string $stderr = ''): CommandRunner
    {
        return new class ($stdout, $code, $stderr) implements CommandRunner {
            /** @var list<list<string>> */
            public array $calls = [];

            public function __construct(private string $stdout, private int $code, private string $stderr) {}

            public function run(array $argv, $stdout, $stderr): int
            {
                $this->calls[] = $argv;
                fwrite($stdout, $this->stdout);
                fwrite($stderr, $this->stderr);

                return $this->code;
            }
        };
    }

    private function pull(CommandRunner $runner, array $args): int
    {
        return (new PullCommand(fn () => $this->app(), $runner, $this->out, $this->err))->execute($args);
    }

    private function text($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    public function test_pull_builds_ssh_command_without_disabling_host_keys(): void
    {
        $runner = $this->runner($this->remoteExport());
        $this->pull($runner, ['production', '--dry-run', '--only=media']);

        $this->assertSame(
            ['ssh', '-o', 'BatchMode=yes', '-p', '2222', 'user@host', "cd '/srv/my site' && '/usr/bin/php84' preflow folio:export --stdout --only=media"],
            $runner->calls[0],
        );
        $this->assertStringNotContainsString('StrictHostKeyChecking', implode(' ', $runner->calls[0]));
    }

    public function test_pull_applies_remote_content_after_backup(): void
    {
        $remote = $this->remoteExport();
        $this->working('post', 'p2', ['title' => ['en' => 'Local only'], 'slug' => ['en' => 'local']]);

        $this->assertSame(0, $this->pull($this->runner($remote), ['production']));

        $this->assertFileDoesNotExist($this->dir . '/storage/data/post/p2.json');
        $this->assertFileExists($this->dir . '/storage/data/post/p1.json');
        $this->assertCount(1, glob($this->dir . '/storage/backups/pull-*.tar.gz'));
        $this->assertStringContainsString('Backup:', $this->text($this->out));
    }

    public function test_pull_dry_run_changes_nothing(): void
    {
        $remote = $this->remoteExport();
        $this->working('post', 'p2', ['title' => ['en' => 'Local only'], 'slug' => ['en' => 'local']]);

        $this->assertSame(0, $this->pull($this->runner($remote), ['production', '--dry-run']));

        $this->assertFileExists($this->dir . '/storage/data/post/p2.json');
        $this->assertDirectoryDoesNotExist($this->dir . '/storage/backups');
        $this->assertMatchesRegularExpression('/post\s+\+0\s+~0\s+-1/', $this->text($this->out));
    }

    public function test_failed_remote_aborts_without_backup_or_changes(): void
    {
        $partial = substr($this->remoteExport(), 0, 700);
        $this->working('post', 'p2', ['title' => ['en' => 'Local only'], 'slug' => ['en' => 'local']]);
        $tmpBefore = glob(sys_get_temp_dir() . '/folio-pull-*') ?: [];

        $this->assertSame(1, $this->pull($this->runner($partial, 255, 'Host key verification failed.'), ['production']));

        $this->assertFileExists($this->dir . '/storage/data/post/p2.json');
        $this->assertDirectoryDoesNotExist($this->dir . '/storage/backups');
        $this->assertStringContainsString('Host key verification failed.', $this->text($this->err));
        $this->assertSame($tmpBefore, glob(sys_get_temp_dir() . '/folio-pull-*') ?: []); // temp file removed
    }

    public function test_truncated_remote_output_with_exit_0_is_refused(): void
    {
        $partial = substr($this->remoteExport(), 0, 700);

        $this->assertSame(1, $this->pull($this->runner("Notice: something\n" . $partial), ['production']));
        $this->assertDirectoryDoesNotExist($this->dir . '/storage/backups');
        $this->assertStringContainsString('besides the archive', $this->text($this->err)); // points at the likely cause
    }

    public function test_unknown_remote_exits_1(): void
    {
        $runner = $this->runner('');

        $this->assertSame(1, $this->pull($runner, ['staging']));
        $this->assertSame(1, $this->pull($runner, []));
        $this->assertSame([], $runner->calls);
    }
}
