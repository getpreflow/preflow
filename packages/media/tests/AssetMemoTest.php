<?php

declare(strict_types=1);

namespace Preflow\Media\Tests;

use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\Locales;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Data\TypeRegistry;
use Preflow\Media\MediaLibrary;
use Preflow\Media\Tests\Support\MediaFixtures;

/** Asset lookups while rendering a list: memoised per library (request), batched by prefetch(), emptied by writes. */
#[RequiresPhpExtension('gd')]
#[RequiresPhpExtension('fileinfo')]
#[RequiresFunction('imagewebp')]
final class AssetMemoTest extends TestCase
{
    use MediaFixtures;

    /** @var list<string> */
    private array $sql = [];
    private MediaLibrary $lib;

    protected function setUp(): void
    {
        $this->makeAssetRoot();
        mkdir($this->assetRoot . '/models');
        $log = &$this->sql;
        $pdo = new class ('sqlite::memory:', $log) extends \PDO {
            /** @param list<string> $log */
            public function __construct(string $dsn, private array &$log)
            {
                parent::__construct($dsn);
            }

            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                $this->log[] = $query;

                return parent::prepare($query, $options);
            }

            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
            {
                $this->log[] = $query;

                return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
            }
        };
        $dm = new DataManager(drivers: ['sqlite' => new SqliteDriver($pdo)], defaultDriver: 'sqlite', typeRegistry: new TypeRegistry($this->assetRoot . '/models'));
        $this->lib = new MediaLibrary($dm, new Locales(['en', 'de'], 'en'), $this->assetRoot . '/media', '/media', ['storage' => 'sqlite', 'versions_dir' => $this->assetRoot . '/versions']);
        (new RecordTableSync($dm))->sync($this->lib->definition());
    }

    protected function tearDown(): void
    {
        $this->removeAssetRoot();
    }

    /** @return list<string> */
    private function upload25(): array
    {
        $ids = [];
        for ($i = 0; $i < 25; $i++) {
            $ids[] = $this->lib->upload($this->upload($this->png(20 + $i, 10), "a{$i}.png"), null)->id;
        }

        return $ids;
    }

    private function assetQueries(): int
    {
        return count(array_filter($this->sql, static fn (string $q): bool => str_contains($q, 'folio_assets') && stripos($q, 'select') !== false));
    }

    public function test_rendering_n_images_runs_one_asset_query(): void
    {
        $ids = $this->upload25();
        $fresh = $this->lib;
        $fresh->flush();
        $this->sql = [];

        $fresh->prefetch($ids);
        foreach ($ids as $id) {
            $this->assertNotNull($fresh->find($id));
            $this->assertNotNull($fresh->find($id)); // the second look is free too
        }

        $this->assertLessThanOrEqual(2, $this->assetQueries());
    }

    public function test_repeated_lookups_without_prefetch_ask_the_storage_once_per_id(): void
    {
        $id = $this->upload25()[0];
        $this->lib->flush();
        $this->sql = [];

        for ($i = 0; $i < 10; $i++) {
            $this->lib->find($id);
        }

        $this->assertSame(1, $this->assetQueries());
    }

    public function test_unknown_ids_still_render_nothing(): void
    {
        $ids = $this->upload25();
        $unknown = str_repeat('a', 32);
        $this->lib->flush();

        $this->lib->prefetch([$ids[0], $unknown, 'not-an-id', 42]);

        $this->assertNotNull($this->lib->find($ids[0]));
        $this->assertNull($this->lib->find($unknown));
        $this->assertNull($this->lib->find('not-an-id'));
        $this->sql = [];
        $this->assertNull($this->lib->find($unknown)); // not memoised: a later write shows up
        $this->assertSame(1, $this->assetQueries());
    }

    public function test_memo_is_cleared_on_write(): void
    {
        $id = $this->upload25()[0];
        $this->assertNotNull($this->lib->find($id)); // memoised

        $this->lib->update($id, ['alt' => ['en' => 'Changed']]);
        $this->assertSame('Changed', $this->lib->find($id)?->altFor('en', 'en'));

        $this->lib->delete($id);
        $this->assertNull($this->lib->find($id));
    }

    public function test_prefetched_assets_equal_plain_finds_in_every_locale(): void
    {
        $id = $this->upload25()[0];
        $this->lib->update($id, ['alt' => ['en' => 'Tree', 'de' => 'Baum', 'fr' => 'Arbre']]);
        $this->lib->flush();
        $plain = $this->lib->find($id);
        $this->lib->flush();
        $this->lib->prefetch([$id]);
        $batched = $this->lib->find($id);

        $this->assertSame($plain->toData(), $batched->toData());
        foreach (['en', 'de', 'fr', 'it'] as $locale) {
            $this->assertSame($plain->altFor($locale, 'en'), $batched->altFor($locale, 'en'));
        }
    }
}
