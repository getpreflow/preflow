<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Transfer;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Migration\MigrationRegistry;
use Preflow\Data\StorageDriver;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\Folio\Transfer\ContentSet;
use Preflow\Folio\Transfer\Exporter;
use Preflow\Folio\Transfer\Importer;
use Preflow\Folio\Transfer\TarWriter;

/**
 * An import replaces a table's records: unique values that move from one id to another
 * (the same slug created separately on live and locally) never collide, and a failure
 * part-way leaves the local records as they were. Run per storage by the subclasses.
 */
abstract class ImportReplacesTestCase extends TestCase
{
    use PublishingScaffold;

    /** 'json', 'sqlite' or 'mysql' */
    abstract protected function storage(): string;

    /** The data the failing import writes through: one that refuses the record with id "z". */
    protected function failingData(DataManager $dm): DataManager
    {
        return $dm;
    }

    /**
     * @param resource $archive
     * @return array{0: Importer, 1: \Preflow\Folio\Transfer\ImportPlan}
     */
    private function failingImport($archive): array
    {
        $app = $this->app();
        $importer = new Importer(ContentSet::fromApp($app), $this->failingData($app->container()->get(DataManager::class)));

        return [$importer, $importer->read($archive, 'data')];
    }

    protected function setUp(): void
    {
        $this->scaffoldPublishing($this->storage());
        $this->writeModel('game', [
            'label' => 'Games',
            'fields' => [
                'title' => ['type' => 'string'],
                'slug' => ['type' => 'string', 'unique' => true],
            ],
        ]);
        if ($this->storage() !== 'json') {
            ($this->app()->container()->get(MigrationRegistry::class)->steps()['folio.tables'])();
        }
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    protected function gameDriver(): StorageDriver
    {
        return $this->dm()->driver($this->storage());
    }

    /** @param array<string, array<string, mixed>> $games id => fields */
    protected function setGames(array $games): void
    {
        $driver = $this->gameDriver();
        foreach ($driver->findMany('game', new \Preflow\Data\Query())->items() as $row) {
            $driver->delete('game', (string) $row['uuid'], 'uuid');
        }
        foreach ($games as $id => $fields) {
            $driver->save('game', $id, ['uuid' => $id] + $fields, 'uuid');
        }
    }

    /** @return array<string, array{title: mixed, slug: mixed}> id => title and slug */
    protected function games(): array
    {
        $out = [];
        foreach ($this->gameDriver()->findMany('game', new \Preflow\Data\Query())->items() as $row) {
            $out[(string) $row['uuid']] = ['title' => $row['title'] ?? null, 'slug' => $row['slug'] ?? null];
        }
        ksort($out);

        return $out;
    }

    /** @return resource a data-only export of the current content */
    protected function export()
    {
        $app = $this->app();
        $s = fopen('php://temp', 'w+b');
        (new Exporter(ContentSet::fromApp($app), $app->container()->get(DataManager::class)))->export($s, 'data');
        rewind($s);

        return $s;
    }

    /**
     * A data archive with only the game table.
     *
     * @param array<string, array<string, mixed>> $rows id => row
     * @return resource
     */
    protected function gameArchive(array $rows)
    {
        $s = fopen('php://temp', 'w+b');
        $w = new TarWriter($s);
        $w->addString('folio-export.json', (string) json_encode(['format' => 1, 'only' => 'data', 'tables' => ['game' => count($rows)]]));
        foreach ($rows as $id => $row) {
            $w->addString("data/game/{$id}.json", (string) json_encode(['uuid' => $id] + $row));
        }
        $w->close();
        rewind($s);

        return $s;
    }

    /** @param resource $archive */
    protected function import($archive): void
    {
        $app = $this->app();
        $importer = new Importer(ContentSet::fromApp($app), $app->container()->get(DataManager::class));
        $plan = $importer->read($archive, 'data');
        try {
            $importer->apply($plan);
        } finally {
            $plan->cleanup();
        }
    }

    public function test_a_slug_on_another_id_replaces_the_local_record(): void
    {
        $this->setGames(['b' => ['title' => 'Live', 'slug' => 'x']]);
        $archive = $this->export();
        $this->setGames(['a' => ['title' => 'Local', 'slug' => 'x']]);

        $this->import($archive);

        $this->assertSame(['b' => ['title' => 'Live', 'slug' => 'x']], $this->games());
    }

    public function test_two_records_swapping_slugs_import(): void
    {
        $this->setGames(['a' => ['title' => 'A', 'slug' => 'y'], 'b' => ['title' => 'B', 'slug' => 'x']]);
        $archive = $this->export();
        $this->setGames(['a' => ['title' => 'A', 'slug' => 'x'], 'b' => ['title' => 'B', 'slug' => 'y']]);

        $this->import($archive);

        $this->assertSame(['a' => ['title' => 'A', 'slug' => 'y'], 'b' => ['title' => 'B', 'slug' => 'x']], $this->games());
    }

    public function test_a_failure_part_way_leaves_the_local_records_unchanged(): void
    {
        $local = ['a' => ['title' => 'Local', 'slug' => 'x'], 'd' => ['title' => 'Old', 'slug' => 'd']];
        $this->setGames($local);
        $archive = $this->gameArchive([
            'b' => ['title' => 'Live', 'slug' => 'y'],
            'd' => ['title' => 'New', 'slug' => 'd'],
            'z' => ['title' => 'Breaks', 'slug' => 'z'] + $this->breakingFields(),
        ]);

        [$importer, $plan] = $this->failingImport($archive);
        try {
            $importer->apply($plan);
            $this->fail('the import should have failed on z');
        } catch (\Throwable $e) {
            $this->assertStringNotContainsString('should have failed', $e->getMessage());
        } finally {
            $plan->cleanup();
        }

        $this->assertSame($local, $this->games());
    }

    /** @return array<string, mixed> extra fields of the row "z" that make the storage refuse it */
    protected function breakingFields(): array
    {
        return [];
    }
}
