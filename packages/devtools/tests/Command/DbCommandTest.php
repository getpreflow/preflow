<?php

declare(strict_types=1);

namespace Preflow\DevTools\Tests\Command;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\DevTools\Command\DbCommand;

/**
 * db:check, db:vacuum, db:dump and db:restore on a temp SQLite site whose table uses the
 * preflow_text collation (which the sqlite3 tool lacks), and the refusal on a storage without the
 * capability.
 */
final class DbCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/db_cmd_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        mkdir($this->dir . '/data', 0777, true);
        file_put_contents($this->dir . '/config/data.php', '<?php return ["default" => "sqlite", "drivers" => ['
            . '"sqlite" => ["path" => ' . var_export($this->dir . '/app.sqlite', true) . '], '
            . '"copy" => ["driver" => "sqlite", "path" => ' . var_export($this->dir . '/copy.sqlite', true) . '], '
            . '"json" => ["path" => ' . var_export($this->dir . '/data', true) . '], '
            . ']];');
        $driver = new SqliteDriver(new \PDO('sqlite:' . $this->dir . '/app.sqlite'));
        $driver->execute('CREATE TABLE "pages" ("uuid" VARCHAR(36) PRIMARY KEY, "slug" VARCHAR(255) COLLATE preflow_text NOT NULL)');
        $driver->execute('CREATE UNIQUE INDEX "uniq_pages_slug" ON "pages" ("slug")');
        $driver->execute('INSERT INTO "pages" VALUES (?, ?), (?, ?)', ['a', 'Über uns', 'b', 'kontakt']);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    /** @return array{0: int, 1: string, 2: string} exit code, standard output, standard error */
    private function db(string $name, array $args = [], ?string $stdin = null): array
    {
        $dir = $this->dir;
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $in = fopen('php://memory', 'w+');
        fwrite($in, (string) $stdin);
        rewind($in);
        $command = new DbCommand($name, static function () use ($dir): Application {
            $app = Application::create($dir);
            $app->boot();

            return $app;
        }, $out, $in, $err);

        $code = $command->execute($args);
        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    public function test_check_and_vacuum_run_through_the_prepared_connection(): void
    {
        [$code, , $err] = $this->db('db:check');
        $this->assertSame(0, $code, $err);
        $this->assertStringContainsString('[default] No problems found.', $err);

        [$code, , $err] = $this->db('db:vacuum');
        $this->assertSame(0, $code, $err);
        $this->assertStringContainsString('[default] Compacted.', $err);
    }

    public function test_dump_to_standard_output_restores_into_an_empty_storage(): void
    {
        [$code, $dump, $err] = $this->db('db:dump');
        $this->assertSame(0, $code, $err);
        $this->assertStringContainsString('COLLATE preflow_text', $dump);
        $this->assertSame('', $err, 'messages stay off a dump on standard output');

        [$code, , $err] = $this->db('db:restore', ['-', '--storage=copy'], $dump);
        $this->assertSame(0, $code, $err);
        $this->assertStringContainsString('[copy] Restored.', $err);

        $copy = new SqliteDriver(new \PDO('sqlite:' . $this->dir . '/copy.sqlite'));
        $this->assertSame('a', $copy->rawQuery('SELECT "uuid" FROM "pages" WHERE "slug" = ?', ['ÜBER UNS'])[0]['uuid'] ?? null);

        [$code, , $err] = $this->db('db:restore', ['-', '--storage=copy'], $dump);
        $this->assertSame(1, $code, 'a storage with tables is refused');
        $this->assertStringContainsString('holds pages', $err);
    }

    public function test_dump_to_a_file_refuses_an_existing_one(): void
    {
        $file = $this->dir . '/backup.sql';

        [$code, , $err] = $this->db('db:dump', [$file]);
        $this->assertSame(0, $code, $err);
        $this->assertStringContainsString('INSERT INTO `pages`', (string) file_get_contents($file));

        [$code, , $err] = $this->db('db:dump', [$file]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('exists', $err);

        [$code, , $err] = $this->db('db:restore', [$file, '--storage=copy']);
        $this->assertSame(0, $code, $err);
    }

    public function test_a_storage_without_the_capability_is_refused_with_the_tools_that_work(): void
    {
        [$code, , $err] = $this->db('db:check', ['--storage=json']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Storage [json] has no db:check', $err);
        $this->assertStringContainsString('mysqlcheck, mysqldump', $err);
    }

    public function test_restore_needs_a_dump_to_read(): void
    {
        [$code, , $err] = $this->db('db:restore', ['--storage=copy']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Name the dump to restore', $err);
    }
}
