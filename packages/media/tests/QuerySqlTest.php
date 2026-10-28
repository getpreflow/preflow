<?php

declare(strict_types=1);

namespace Preflow\Media\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\MysqlDriver;
use Preflow\Data\Driver\PdoDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\Locales;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Data\TypeRegistry;
use Preflow\Media\MediaLibrary;
use Preflow\Media\Tests\Support\MediaFixtures;
use Preflow\Testing\MysqlTestDatabase;

/** Extra fields and query() on SQL storage: the columns come from the table step, lists are JSON text. */
#[RequiresPhpExtension('gd')]
#[RequiresPhpExtension('fileinfo')]
#[RequiresFunction('imagewebp')]
final class QuerySqlTest extends TestCase
{
    use MediaFixtures;

    private bool $mysql = false;

    protected function setUp(): void
    {
        $this->makeAssetRoot();
    }

    protected function tearDown(): void
    {
        if ($this->mysql) {
            MysqlTestDatabase::dropAllTables(MysqlTestDatabase::pdo());
        }
        $this->removeAssetRoot();
    }

    public function test_on_sqlite(): void
    {
        $this->assertExtraFieldsAndQuery('sqlite', new SqliteDriver(new \PDO('sqlite::memory:')));
    }

    #[Group('mysql')]
    public function test_on_mysql(): void
    {
        $pdo = MysqlTestDatabase::pdo();
        MysqlTestDatabase::dropAllTables($pdo);
        $this->mysql = true;
        $this->assertExtraFieldsAndQuery('mysql', new MysqlDriver($pdo));
    }

    private function assertExtraFieldsAndQuery(string $storage, PdoDriver $driver): void
    {
        mkdir($this->assetRoot . '/models');
        $dm = new DataManager(drivers: [$storage => $driver], defaultDriver: $storage, typeRegistry: new TypeRegistry($this->assetRoot . '/models'));
        $lib = new MediaLibrary($dm, new Locales(['en', 'de'], 'en'), $this->assetRoot . '/media', '/media', [
            'storage' => $storage,
            'versions_dir' => $this->assetRoot . '/versions',
            'private_dir' => $this->assetRoot . '/private',
            'fields' => ExtraFieldsTest::fields(),
            'filters' => ['usage_type', 'game', 'featured'],
        ]);

        // An asset from before the fields existed: the columns are added later and hold NULL for it.
        $plain = new MediaLibrary($dm, new Locales(['en', 'de'], 'en'), $this->assetRoot . '/media', '/media', ['storage' => $storage, 'versions_dir' => $this->assetRoot . '/versions']);
        (new RecordTableSync($dm))->sync($plain->definition());
        $old = $plain->upload($this->upload($this->png(40, 30), 'old.png'), null);
        $this->assertSame(['added column folio_assets.usage_type', 'added column folio_assets.game', 'added column folio_assets.featured'], (new RecordTableSync($dm))->sync($lib->definition()));
        $this->assertSame([$old->id], array_map(static fn ($x) => $x->id, $lib->query(filters: ['featured' => false])->items));
        $this->assertSame([], $lib->query(filters: ['featured' => true])->items);

        $a = $lib->upload($this->upload($this->png(40, 30), 'a.png'), null);
        $b = $lib->upload($this->upload($this->png(40, 30), 'b.png'), null);
        $c = $lib->upload($this->upload("%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n", 'rules.pdf'), null);
        $lib->update($a->id, ['usage_type' => 'cover', 'game' => ['g1'], 'alt' => ['en' => 'A red barn'], 'featured' => true]);
        $lib->update($b->id, ['usage_type' => 'step', 'game' => ['g1', 'g2']]);

        $this->assertSame(['cover', ['g1'], true], [$lib->find($a->id)->extra['usage_type'], $lib->find($a->id)->extra['game'], $lib->find($a->id)->extra['featured']]);
        $ids = static fn (array $items): array => array_values(array_map(static fn ($x) => $x->id, $items));
        $sorted = static function (array $list): array {
            sort($list);

            return $list;
        };

        $this->assertSame($sorted([$old->id, $a->id, $b->id, $c->id]), $sorted($ids($lib->query()->items)));
        $this->assertSame([$a->id], $ids($lib->query(filters: ['usage_type' => 'cover'])->items));
        $this->assertSame($sorted([$a->id, $b->id]), $sorted($ids($lib->query(filters: ['game' => 'g1'])->items)));
        $this->assertSame([$b->id], $ids($lib->query(filters: ['game' => 'g2'])->items));
        $this->assertSame([$a->id], $ids($lib->query(filters: ['featured' => true])->items));
        $this->assertSame([$c->id], $ids($lib->query(kind: 'document')->items));
        $this->assertSame($sorted([$old->id, $a->id, $b->id]), $sorted($ids($lib->query(kind: 'image')->items)));
        $this->assertSame([$a->id], $ids($lib->query(search: 'red barn')->items));
        $this->assertSame([$c->id], $ids($lib->query(search: 'rules')->items));
        $lib->update($b->id, ['alt' => ['de' => 'Eine blaue Scheune']]);
        $this->assertSame([$b->id], $ids($lib->query(search: 'Scheune')->items)); // another locale's alt text
        $this->assertSame([], $ids($lib->query(search: 'red Scheune')->items));
        $this->assertSame([], $ids($lib->query(filters: ['nonsense' => 'x', 'game' => 'g9'])->items));

        $second = $lib->query(page: 2, perPage: 2);
        $this->assertSame(4, $second->total);
        $this->assertCount(2, $second->items);
        $this->assertSame($sorted([$old->id, $b->id, $c->id]), $sorted($ids($lib->query(filters: ['featured' => false])->items)));

        // The private flag (stored 1/0) filters like a boolean.
        $p = $lib->upload($this->upload($this->png(10, 10), 'p.png'), null, private: true);
        $this->assertSame([$p->id], $ids($lib->query(filters: ['private' => true])->items));
        $this->assertSame($sorted([$old->id, $a->id, $b->id, $c->id]), $sorted($ids($lib->query(filters: ['private' => false])->items)));
        $lib->discard($p->id);

        // Extra values survive a replace and a restore.
        $lib->replace($a->id, $this->upload($this->png(20, 10), 'a2.png'), null);
        $restored = $lib->restore($a->id, 1, null);
        $this->assertSame(['cover', ['g1'], true], [$restored->extra['usage_type'], $restored->extra['game'], $restored->extra['featured']]);
    }

    /** A SQLite library with a.png, rules.pdf and a copy of a.png; the copy last. @return array{0: MediaLibrary, 1: string, 2: string, 3: string, 4: \PDO} */
    private function sqliteWithCopy(): array
    {
        $pdo = new \PDO('sqlite::memory:');
        mkdir($this->assetRoot . '/models');
        $dm = new DataManager(drivers: ['sqlite' => new SqliteDriver($pdo)], defaultDriver: 'sqlite', typeRegistry: new TypeRegistry($this->assetRoot . '/models'));
        $lib = new MediaLibrary($dm, new Locales(['en', 'de'], 'en'), $this->assetRoot . '/media', '/media', ['storage' => 'sqlite', 'versions_dir' => $this->assetRoot . '/versions']);
        (new RecordTableSync($dm))->sync($lib->definition());
        $a = $lib->upload($this->upload($this->png(40, 30), 'a.png'), null);
        $doc = $lib->upload($this->upload("%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n", 'rules.pdf'), null);
        file_put_contents($this->assetRoot . '/made.png', $this->png(40, 30));
        $copy = $lib->derive($a->id, $this->assetRoot . '/made.png', null, 'watermark', ['line' => 'x']);

        return [$lib, $a->id, $doc->id, $copy->id, $pdo];
    }

    /** @return list<string> sorted ids */
    private static function idsOf(\Preflow\Media\AssetPage $page): array
    {
        $ids = array_map(static fn ($x) => $x->id, $page->items);
        sort($ids);

        return $ids;
    }

    /** @param list<string> $ids @return list<string> */
    private static function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    public function test_copies_hidden_by_default(): void
    {
        [$lib, $a, $doc, $copy, $pdo] = $this->sqliteWithCopy();

        $this->assertSame(self::sorted([$a, $doc]), self::idsOf($lib->query()));
        $this->assertSame(2, $lib->query()->total);
        $this->assertSame(1, $lib->query(kind: 'image')->total);
        $this->assertSame(2, $lib->query(copies: false)->total);
        $this->assertSame(['line' => 'x'], $lib->find($copy)->makerParams); // JSON text in the column

        // Records from before copies existed hold NULL there and are originals.
        $pdo->exec('UPDATE folio_assets SET derived_from = NULL WHERE derived_from = \'\'');
        $this->assertSame(self::sorted([$a, $doc]), self::idsOf($lib->query()));
        $this->assertNull($lib->find($a)->derivedFrom);
    }

    public function test_search_finds_copies(): void
    {
        [$lib, $a, , $copy] = $this->sqliteWithCopy();

        $page = $lib->query(search: 'a.png');
        $this->assertSame(self::sorted([$a, $copy]), self::idsOf($page));
        $this->assertSame(2, $page->total);
    }

    public function test_copies_true_includes_them(): void
    {
        [$lib, $a, $doc, $copy] = $this->sqliteWithCopy();

        $page = $lib->query(copies: true);
        $this->assertSame(self::sorted([$a, $doc, $copy]), self::idsOf($page));
        $this->assertSame(3, $page->total);
        $this->assertSame([$copy], array_map(static fn ($x) => $x->id, $lib->copies($a)));
        $this->assertSame([$a => 1, $doc => 0], $lib->copyCounts([$a, $doc]));
    }
}
