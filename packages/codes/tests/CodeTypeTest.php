<?php

declare(strict_types=1);

namespace Preflow\Codes\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Codes\CodeType;
use Preflow\Codes\Codes;
use Preflow\Core\Application;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\PdoDriver;
use Preflow\Data\Migration\MigrationRegistry;
use Preflow\Data\Migration\Migrator;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Data\TypeRegistry;

final class CodeTypeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/codetype_app_' . bin2hex(random_bytes(4));
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Codes', 'debug' => 1, 'engine' => 'twig', 'key' => 'codes-app-test-key'];\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Codes\\CodesServiceProvider::class];\n");
        $this->write('config/data.php', "<?php\nreturn " . var_export([
            'default' => 'sqlite',
            'drivers' => ['sqlite' => ['path' => $this->dir . '/storage/app.sqlite']],
        ], true) . ";\n");
        $this->write('config/codes.php', "<?php\nreturn " . var_export([
            'purposes' => ['t.x' => ['label' => 'Test', 'readable' => true]],
        ], true) . ";\n");
        @mkdir($this->dir . '/storage/locks', 0777, true);
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

    private function write(string $rel, string $content): void
    {
        $path = $this->dir . '/' . $rel;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);
    }

    public function test_define_registers_code_type_without_folio(): void
    {
        $app = Application::create($this->dir);
        $app->boot();
        $c = $app->container();
        self::assertTrue($c->get(DataManager::class)->getTypeRegistry()?->has('code'));

        $registry = $c->get(MigrationRegistry::class);
        $driver = $c->get(DataManager::class)->driver('default');
        self::assertInstanceOf(PdoDriver::class, $driver);
        (new Migrator($driver->pdo(), $registry->paths()))->migrate();
        foreach ($registry->steps() as $step) {
            $step();
        }
        $columns = array_column($driver->pdo()->query('PRAGMA table_info(code)')->fetchAll(\PDO::FETCH_ASSOC), 'name');
        self::assertContains('recipient', $columns);

        $issued = $c->get(Codes::class)->issue('t.x');
        self::assertNotSame("", $issued);
    }

    public function test_storage_falls_back_to_default_without_codes_alias(): void
    {
        self::assertSame('default', CodeType::prepare(CodeType::schema(), ['default'])['storage']);
        self::assertSame('codes', CodeType::prepare(CodeType::schema(), ['default', 'codes'])['storage']);
    }

    public function test_existing_folio_synced_table_gets_recipient_and_keeps_rows(): void
    {
        $app = Application::create($this->dir);
        $app->boot();
        $c = $app->container();
        $pdo = $c->get(DataManager::class)->driver('default')->pdo();
        $pdo->exec('CREATE TABLE code (uuid TEXT PRIMARY KEY, purpose TEXT, code TEXT, label TEXT, expires_at TEXT, max_uses TEXT, uses TEXT, revoked_at TEXT, payload TEXT, form TEXT)');
        $pdo->exec("INSERT INTO code (uuid, purpose, code, uses, form) VALUES ('u1', 't.x', 'ABCD', '0', 'readable')");

        $definition = $c->get(TypeRegistry::class)->get('code');
        (new RecordTableSync($c->get(DataManager::class)))->sync($definition);

        $columns = array_column($pdo->query('PRAGMA table_info(code)')->fetchAll(\PDO::FETCH_ASSOC), 'name');
        self::assertContains('recipient', $columns);
        self::assertSame('ABCD', $pdo->query("SELECT code FROM code WHERE uuid = 'u1'")->fetchColumn());
    }
}
