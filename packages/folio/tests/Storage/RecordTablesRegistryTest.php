<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Storage;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\PdoDriver;
use Preflow\Data\Migration\Schema;
use Preflow\Data\Schema\RecordTables;
use Preflow\Folio\Tests\Members\Support\MembersScaffold;

/** Every record table a package syncs is in RecordTables, so the conversion finds them all. */
final class RecordTablesRegistryTest extends TestCase
{
    use MembersScaffold;

    protected function setUp(): void
    {
        $this->scaffold(['members' => 'sqlite', 'auth' => 'sqlite', 'throttle' => 'sqlite', 'codes' => 'sqlite']);
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Rbac\\RbacServiceProvider::class, "
            . "\\Preflow\\Members\\MembersServiceProvider::class, \\Preflow\\Codes\\CodesServiceProvider::class];\n");
        $this->write('config/media.php', "<?php\nreturn ['storage' => 'sqlite'];\n");
        $this->write('config/models/event.json', (string) json_encode([
            'key' => 'event', 'table' => 'event', 'storage' => 'sqlite', 'id_field' => 'uuid', 'label' => 'Events', 'publishing' => true,
            'fields' => ['name' => ['type' => 'string'], 'seats' => ['type' => 'number']],
        ]));
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    public function testRegistryListsEveryRecordTable(): void
    {
        $tables = [];
        foreach ($this->app()->container()->get(RecordTables::class)->all() as $entry) {
            $tables[$entry['def']->table] = $entry;
        }

        foreach (['code', 'member', 'folio_assets', 'folio_user_preferences', 'folio_translation_overrides', 'folio_extension_state', 'event', 'event__published', 'event__revisions'] as $table) {
            $this->assertArrayHasKey($table, $tables);
        }
        $this->assertFalse($tables['event__revisions']['typed']);
        $this->assertFalse($tables['event__published']['unique']);
        $this->assertTrue($tables['code']['typed'] && $tables['code']['unique']);
    }

    /** What migrate created on SQL storage is what the registry lists, typed. */
    public function testMigrateCreatesTheRegisteredTables(): void
    {
        $this->migrate();

        $dm = $this->app()->container()->get(DataManager::class);
        foreach ($this->app()->container()->get(RecordTables::class)->all() as $entry) {
            $driver = $dm->driver($entry['def']->storage);
            if ($driver instanceof PdoDriver) {
                $this->assertTrue((new Schema($driver->pdo()))->hasTable($entry['def']->table), $entry['def']->table);
            }
        }
        $pdo = $dm->driver('sqlite');
        \assert($pdo instanceof PdoDriver);
        $this->assertSame('numeric', (new Schema($pdo->pdo()))->columnTypes('event')['seats']);
        $this->assertSame('integer', (new Schema($pdo->pdo()))->columnTypes('folio_assets')['private']);
    }
}
