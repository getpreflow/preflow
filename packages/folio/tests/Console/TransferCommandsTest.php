<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Console;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Console\ExportCommand;
use Preflow\Folio\Console\ImportCommand;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\Folio\Transfer\TarReader;

final class TransferCommandsTest extends TestCase
{
    use PublishingScaffold;

    /** @var resource */
    private $out;
    /** @var resource */
    private $err;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello']]);
        @mkdir($this->dir . '/public/media/2026/10', 0777, true);
        file_put_contents($this->dir . '/public/media/2026/10/a.jpg', 'JPEG');
        $this->out = fopen('php://temp', 'w+b');
        $this->err = fopen('php://temp', 'w+b');
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    private function export(array $args): int
    {
        return (new ExportCommand(fn () => $this->app(), $this->out, $this->err))->execute($args);
    }

    private function import(array $args): int
    {
        return (new ImportCommand(fn () => $this->app(), $this->out, $this->err))->execute($args);
    }

    private function text($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    /** @return list<string> */
    private function names($stream): array
    {
        rewind($stream);

        return array_map(static fn (array $e) => $e[0], iterator_to_array((new TarReader($stream))->entries(), false));
    }

    public function test_export_to_stdout_is_a_valid_archive_and_nothing_else(): void
    {
        $this->assertSame(0, $this->export(['--stdout']));

        $names = $this->names($this->out);
        $this->assertSame('folio-export.json', $names[0]);
        $this->assertContains('data/post/p1.json', $names);
        $this->assertContains('media/2026/10/a.jpg', $names);
        $this->assertStringContainsString('Exported', $this->text($this->err)); // messages go to stderr only
    }

    public function test_export_to_gz_file(): void
    {
        $file = $this->dir . '/backup.tar.gz';
        $this->assertSame(0, $this->export([$file]));

        $this->assertSame("\x1f\x8b", substr((string) file_get_contents($file), 0, 2));
        $this->assertContains('data/post/p1.json', $this->names(fopen('compress.zlib://' . $file, 'rb')));
    }

    public function test_export_rejects_unknown_only(): void
    {
        $this->assertSame(1, $this->export(['--stdout', '--only=code']));
    }

    public function test_import_dry_run_writes_nothing_and_no_backup(): void
    {
        $file = $this->dir . '/x.tar';
        $this->export([$file]);
        unlink($this->dir . '/storage/data/post/p1.json');

        $this->assertSame(0, $this->import([$file, '--dry-run']));

        $this->assertFileDoesNotExist($this->dir . '/storage/data/post/p1.json');
        $this->assertDirectoryDoesNotExist($this->dir . '/storage/backups');
        $this->assertMatchesRegularExpression('/post\s+\+1\s+~0\s+-0/', $this->text($this->out));
    }

    public function test_import_writes_backup_before_applying(): void
    {
        $file = $this->dir . '/x.tar';
        $this->export([$file]);
        $this->working('post', 'p2', ['title' => ['en' => 'Only local'], 'slug' => ['en' => 'local']]);

        $this->assertSame(0, $this->import([$file]));

        $this->assertFileDoesNotExist($this->dir . '/storage/data/post/p2.json');
        $backups = glob($this->dir . '/storage/backups/import-*.tar.gz');
        $this->assertCount(1, $backups);
        $this->assertStringContainsString('cache:clear', $this->text($this->out));

        $this->assertSame(0, $this->import([$backups[0]])); // the backup restores the pre-import state
        $this->assertFileExists($this->dir . '/storage/data/post/p2.json');
    }

    public function test_export_to_stdout_sends_php_messages_to_stderr(): void
    {
        $before = ini_get('display_errors');
        try {
            $this->export(['--stdout']);
            $this->assertSame('stderr', ini_get('display_errors'));
        } finally {
            ini_set('display_errors', (string) $before);
        }
    }

    public function test_failure_part_way_reports_the_backup_to_restore(): void
    {
        $file = $this->dir . '/x.tar';
        $this->export([$file]);
        $this->working('post', 'p2', ['title' => ['en' => 'Only local'], 'slug' => ['en' => 'local']]);
        // Media can no longer be written: public/media is now a plain file.
        foreach (glob($this->dir . '/public/media/2026/10/*') as $f) {
            unlink($f);
        }
        rmdir($this->dir . '/public/media/2026/10');
        rmdir($this->dir . '/public/media/2026');
        rmdir($this->dir . '/public/media');
        file_put_contents($this->dir . '/public/media', 'not a directory');

        $code = $this->import([$file]);

        $this->assertSame(1, $code);
        $msg = $this->text($this->err);
        $this->assertStringContainsString('stopped part-way', $msg);
        $this->assertMatchesRegularExpression('#php preflow folio:import \S+/storage/backups/import-\d{8}-\d{6}\.tar\.gz#', $msg);
    }

    public function test_import_bad_file_exits_1_and_changes_nothing(): void
    {
        file_put_contents($this->dir . '/bad.tar', str_repeat('x', 2048));
        unlink($this->dir . '/storage/data/post/p1.json');
        $this->working('post', 'p3', ['title' => ['en' => 'Keep'], 'slug' => ['en' => 'keep']]);

        $this->assertSame(1, $this->import([$this->dir . '/bad.tar']));
        $this->assertSame(1, $this->import([$this->dir . '/missing.tar']));
        $this->assertSame(1, $this->import([]));

        $this->assertFileExists($this->dir . '/storage/data/post/p3.json');
        $this->assertDirectoryDoesNotExist($this->dir . '/storage/backups');
        $this->assertNotSame('', $this->text($this->err));
    }
}
