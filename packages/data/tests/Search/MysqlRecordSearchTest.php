<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Search;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\MysqlDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\Data\Migration\Grammar\MysqlGrammar;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Data\Search\RecordHit;
use Preflow\Data\Search\RecordSearch;
use Preflow\Data\TypeRegistry;
use Preflow\Testing\MysqlTestDatabase;

/** ⌘K on MySQL: a type that declares full-text search is ranked through its FULLTEXT index. */
#[Group('mysql')]
final class MysqlRecordSearchTest extends TestCase
{
    private \PDO $pdo;

    private string $dir;

    protected function setUp(): void
    {
        $this->pdo = MysqlTestDatabase::pdo();
        MysqlTestDatabase::dropAllTables($this->pdo);
        $this->dir = sys_get_temp_dir() . '/mrs_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        file_put_contents($this->dir . '/post.json', json_encode([
            'key' => 'post', 'table' => 'post', 'storage' => 'mysql', 'id_field' => 'uuid', 'titleField' => 'title', 'fullText' => true,
            'fields' => ['title' => ['type' => 'string'], 'body' => ['type' => 'text']],
        ]));
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            MysqlTestDatabase::dropAllTables($this->pdo);
        }
        if (isset($this->dir)) {
            foreach (glob($this->dir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->dir);
        }
    }

    /** The flagged type, its table made by the sync (with its FULLTEXT index), and its records. */
    private function manager(): DataManager
    {
        $registry = new TypeRegistry($this->dir, new Locales(['en'], 'en'));
        $dm = new DataManager(drivers: ['mysql' => new MysqlDriver($this->pdo)], defaultDriver: 'mysql', typeRegistry: $registry);
        (new RecordTableSync($dm))->sync($registry->get('post'));
        foreach ([
            'p1' => ['Garden', 'A shed by the fence'],
            'p2' => ['Garden garden', 'Garden gnomes in the garden'],
            'p3' => ['Kitchen knives', 'Sharp and sturdy'],
            'p4' => ['Garden party', 'Music and food'],
            'p5' => ['Winter coats', 'Warm wool'],
            'p6' => ['City maps', 'Streets and squares'],
            'p7' => ['Sea charts', 'Tides and coasts'],
        ] as $id => [$title, $body]) {
            $dm->saveType(DynamicRecord::fromArray($registry->get('post'), ['uuid' => $id, 'title' => $title, 'body' => $body]), validate: false);
        }

        return $dm;
    }

    /** @return list<string> */
    private static function looseIds(DataManager $dm, string $term): array
    {
        return array_map(static fn (DynamicRecord $r): string => (string) $r->getId(), $dm->queryType('post')->searchLoose($term)->get()->items());
    }

    public function test_mysql_ranks_a_flagged_type(): void
    {
        $dm = $this->manager();
        $this->assertTrue($dm->canScore($dm->getTypeRegistry()->get('post')));

        $hits = (new RecordSearch($dm))->search('garden', ['post']);

        $this->assertSame(['p2', 'p1', 'p4'], array_map(static fn (RecordHit $h): string => $h->id, $hits), 'the best match first');
        $this->assertGreaterThan($hits[1]->score, $hits[0]->score);
        $ids = array_map(static fn (RecordHit $h): string => $h->id, $hits);
        $loose = self::looseIds($dm, 'garden');
        sort($ids);
        sort($loose);
        $this->assertSame($loose, $ids, 'the same set loose search finds');
    }

    public function test_a_flagged_type_without_its_index_keeps_storage_order(): void
    {
        $dm = $this->manager();
        $grammar = new MysqlGrammar();
        foreach (array_keys($grammar->fullTextIndexes($this->pdo, 'post')) as $index) {
            $this->pdo->exec($grammar->dropIndex('post', $index)); // as before migrate made it
        }
        $this->assertFalse($dm->canScore($dm->getTypeRegistry()->get('post')));

        $hits = (new RecordSearch($dm))->search('garden', ['post']);

        $this->assertSame(self::looseIds($dm, 'garden'), array_map(static fn (RecordHit $h): string => $h->id, $hits));
        $this->assertSame([null, null, null], array_map(static fn (RecordHit $h): ?float => $h->score, $hits));
    }

    /** The index is dropped after canScore() said yes (cached for the driver's life): storage order, no error. */
    public function test_an_index_dropped_after_can_score_keeps_storage_order(): void
    {
        $dm = $this->manager();
        $this->assertTrue($dm->canScore($dm->getTypeRegistry()->get('post')));
        $grammar = new MysqlGrammar();
        foreach (array_keys($grammar->fullTextIndexes($this->pdo, 'post')) as $index) {
            $this->pdo->exec($grammar->dropIndex('post', $index));
        }

        $hits = (new RecordSearch($dm))->search('garden', ['post']);

        $this->assertSame(self::looseIds($dm, 'garden'), array_map(static fn (RecordHit $h): string => $h->id, $hits));
        $this->assertFalse($dm->canScore($dm->getTypeRegistry()->get('post')), 'asked again after the failure');
    }
}
