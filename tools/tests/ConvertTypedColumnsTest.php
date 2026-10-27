<?php

declare(strict_types=1);

namespace Preflow\Tools\Convert\Tests;

require_once __DIR__ . '/ConvertScenarios.php';

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Tools\Convert\Backup;
use Preflow\Tools\Convert\Cli;
use Preflow\Tools\Convert\Overlay;

/** tools/convert-typed-columns.php on SQLite: the table is rebuilt typed, in one transaction. */
final class ConvertTypedColumnsTest extends TestCase
{
    use ConvertScenarios;

    private const string STORAGE = 'sqlite';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/convert_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        $this->backups = $this->dir . '/backups';
        $this->dm = new DataManager(['sqlite' => new SqliteDriver(new \PDO('sqlite:' . $this->dir . '/site.sqlite'))], 'sqlite');
    }

    protected function tearDown(): void
    {
        unset($this->dm);
        self::removeDir($this->dir);
    }

    private function backup(): Backup
    {
        return new Backup($this->pdo(), $this->backups);
    }

    private function storage(): string
    {
        return self::STORAGE;
    }

    public function testConvertedTableIsCreatedAsSyncCreatesIt(): void
    {
        $this->legacy(self::event());
        $this->insert('event', [['uuid' => 'a', 'seats' => '1']]);
        $this->pdo()->exec('CREATE INDEX own_event_name ON event (name)');

        $this->assertSame(0, $this->conversion()->run(false, static fn (string $line) => null));

        $fresh = new \PDO('sqlite:' . $this->dir . '/fresh.sqlite');
        $freshDm = new DataManager(['sqlite' => new SqliteDriver($fresh)], 'sqlite');
        (new \Preflow\Data\Schema\RecordTableSync($freshDm))->sync(self::event());
        $sql = static fn (\PDO $pdo): array => $pdo->query("SELECT type, name, sql FROM sqlite_master WHERE tbl_name = 'event' AND name <> 'own_event_name' ORDER BY name")->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertSame($sql($fresh), $sql($this->pdo()));
        $this->assertContains('own_event_name', (new \Preflow\Data\Migration\Schema($this->pdo()))->indexes('event'), 'other indexes are kept');
    }

    public function testBackupIsACopyOfTheDatabase(): void
    {
        $this->legacy(self::event());
        $this->insert('event', [['uuid' => 'a', 'seats' => '12 Spieler']]);

        $path = $this->backup()->create();

        $this->assertStringStartsWith($this->backups . '/', $path);
        $this->assertSame('12 Spieler', (new \PDO('sqlite:' . $path))->query('SELECT seats FROM event')->fetchColumn());
    }

    public function testOverlayPointsTheSiteAtACopyAndLeavesTheSiteAlone(): void
    {
        $site = $this->dir . '/site';
        mkdir($site . '/config', 0777, true);
        mkdir($site . '/storage/data', 0777, true);
        file_put_contents($site . '/.env', "APP_DEBUG=0\n");
        file_put_contents($site . '/config/app.php', "<?php return ['name' => 'x'];\n");
        file_put_contents($site . '/config/data.php', "<?php return ['drivers' => ['sqlite' => ['path' => __DIR__ . '/../storage/data/site.sqlite'], 'json' => []], 'default' => 'json'];\n");
        $copy = $this->dir . '/copy.sqlite';
        touch($copy);

        $overlay = Overlay::create($site, $copy, $this->dir);
        $base = $overlay->basePath();

        $this->assertNotSame($site, $base);
        $config = require $base . '/config/data.php';
        $this->assertSame($copy, $config['drivers']['sqlite']['path']);
        $this->assertSame(['x'], [(require $base . '/config/app.php')['name']]);
        $this->assertFileExists($base . '/.env');
        $this->assertDirectoryExists($base . '/storage/data');

        $overlay->remove();

        $this->assertDirectoryDoesNotExist($base);
        $this->assertFileExists($site . '/config/data.php');
        $this->assertFileExists($site . '/.env');
        $this->assertDirectoryExists($site . '/storage/data');
        $this->assertFileDoesNotExist($site . '/storage/data/site.sqlite');
    }

    /** A dry run on a copy writes nothing into the site: its backups go next to the copy. */
    public function testBackupDirIsBesideTheCopyWithSqlite(): void
    {
        $this->assertSame('/site/storage/backups', Cli::backupDir('/site', null, null));
        $this->assertSame('/scratch/backups', Cli::backupDir('/site', '/scratch/site-copy.sqlite', null));
        $this->assertSame('/elsewhere', Cli::backupDir('/site', '/scratch/site-copy.sqlite', '/elsewhere'));
        $this->assertSame('/elsewhere', Cli::backupDir('/site', null, '/elsewhere'));
    }

    public function testOverlayRefusesASiteWithoutSqlite(): void
    {
        $site = $this->dir . '/site';
        mkdir($site . '/config', 0777, true);
        file_put_contents($site . '/config/data.php', "<?php return ['drivers' => ['json' => []]];\n");
        $copy = $this->dir . '/copy.sqlite';
        touch($copy);

        $overlay = Overlay::create($site, $copy, $this->dir);
        try {
            $this->expectExceptionMessage('no sqlite storage');
            require $overlay->basePath() . '/config/data.php';
        } finally {
            $overlay->remove();
        }
    }
}
