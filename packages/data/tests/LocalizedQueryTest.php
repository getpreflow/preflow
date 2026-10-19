<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\MysqlDialect;
use Preflow\Data\Driver\QueryCompiler;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\LocaleValue;
use Preflow\Data\Locales;
use Preflow\Data\Query;
use Preflow\Data\SortDirection;
use Preflow\Data\TypeRegistry;

/** Locale-aware where/search/order on translatable fields, for every driver. */
final class LocalizedQueryTest extends TestCase
{
    private string $dir;

    private Locales $locales;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/lq_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0777, true);
        $this->locales = new Locales(['en', 'de'], 'en');
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->dir);
    }

    /** @return iterable<string, array{string}> */
    public static function drivers(): iterable
    {
        yield 'json' => ['json'];
        yield 'sqlite' => ['sqlite'];
    }

    #[DataProvider('drivers')]
    public function test_where_matches_requested_locale(string $driver): void
    {
        $dm = $this->seeded($driver);

        $this->assertSame('A', $dm->queryType('page')->locale('de')->where('slug', 'ueber')->first()?->getId());
        $this->assertNull($dm->queryType('page')->locale('en')->where('slug', 'ueber')->first());
    }

    #[DataProvider('drivers')]
    public function test_without_locale_matches_default(string $driver): void
    {
        $this->assertSame('A', $this->seeded($driver)->queryType('page')->where('slug', 'about')->first()?->getId());
    }

    #[DataProvider('drivers')]
    public function test_legacy_scalar_counts_as_default_locale(string $driver): void
    {
        $dm = $this->seeded($driver);

        $this->assertSame('B', $dm->queryType('page')->locale('en')->where('slug', 'legacy')->first()?->getId());
        $this->assertNull($dm->queryType('page')->locale('de')->where('slug', 'legacy')->first());
    }

    #[DataProvider('drivers')]
    public function test_same_value_in_two_locales(string $driver): void
    {
        $dm = $this->seeded($driver);

        $this->assertSame('C', $dm->queryType('page')->locale('de')->where('slug', 'same')->first()?->getId());
        $this->assertSame('C', $dm->queryType('page')->locale('en')->where('slug', 'same')->first()?->getId());
    }

    #[DataProvider('drivers')]
    public function test_search_uses_locale(string $driver): void
    {
        $ids = array_map(
            fn ($r) => $r->getId(),
            $this->seeded($driver)->queryType('page')->locale('de')->search('ueb', ['slug'])->get()->items(),
        );

        $this->assertSame(['A'], $ids);
    }

    #[DataProvider('drivers')]
    public function test_order_by_localized_title(string $driver): void
    {
        $ids = array_map(
            fn ($r) => $r->getId(),
            $this->seeded($driver)->queryType('page')->locale('de')->where('slug', 'ueber')->orWhere('slug', 'same')
                ->orderBy('title', SortDirection::Asc)->get()->items(),
        );

        // de titles: A "Zebra", C "Affe"
        $this->assertSame(['C', 'A'], $ids);
    }

    public function test_pdo_round_trips_map(): void
    {
        $dm = $this->seeded('sqlite');

        $record = $dm->findType('page', 'A');

        $this->assertSame(['en' => 'about', 'de' => 'ueber'], LocaleValue::map($record->get('slug'), $this->locales));
    }

    public function test_invalid_locale_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->seeded('sqlite')->queryType('page')->locale("de'); DROP TABLE page; --")->where('slug', 'x')->get();
    }

    public function test_mysql_compiles_localized_column(): void
    {
        $query = (new Query())->where('slug', 'ueber');
        $query->localize(['slug'], 'de', 'en');

        [$sql, $bindings] = (new QueryCompiler(new MysqlDialect()))->compile('page', $query);

        $this->assertStringContainsString("JSON_UNQUOTE(JSON_EXTRACT(`slug`, '$.\"de\"'))", $sql);
        $this->assertStringContainsString("JSON_TYPE(`slug`) = 'OBJECT'", $sql);
        $this->assertSame(['ueber'], $bindings);
    }

    private function seeded(string $driver): DataManager
    {
        file_put_contents($this->dir . '/models/page.json', json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => $driver, 'id_field' => 'uuid',
            'fields' => [
                'title' => ['type' => 'string', 'translatable' => true],
                'slug' => ['type' => 'string', 'translatable' => true, 'searchable' => true],
            ],
        ]));

        if ($driver === 'sqlite') {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->exec('CREATE TABLE page (uuid TEXT PRIMARY KEY, title TEXT, slug TEXT, _locales TEXT)');
            $storage = new SqliteDriver($pdo);
        } else {
            $storage = new JsonFileDriver($this->dir . '/store');
        }

        $registry = new TypeRegistry($this->dir . '/models', $this->locales);
        $dm = new DataManager(drivers: [$driver => $storage], defaultDriver: $driver, typeRegistry: $registry);
        $type = $registry->get('page');

        foreach ([
            ['uuid' => 'A', 'title' => ['en' => 'Zebra', 'de' => 'Zebra'], 'slug' => ['en' => 'about', 'de' => 'ueber']],
            ['uuid' => 'B', 'title' => 'Legacy', 'slug' => 'legacy'],
            ['uuid' => 'C', 'title' => ['en' => 'Monkey', 'de' => 'Affe'], 'slug' => ['en' => 'same', 'de' => 'same']],
        ] as $row) {
            $dm->saveType(DynamicRecord::fromArray($type, $row), validate: false);
        }

        return $dm;
    }

    private function rrmdir(string $dir): void
    {
        foreach (glob($dir . '/{,.}[!.,!..]*', GLOB_MARK | GLOB_BRACE) ?: [] as $path) {
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    public function test_sqlite_round_trips_visibility(): void
    {
        $dm = $this->seeded('sqlite');
        $type = $dm->getTypeRegistry()->get('page');
        $dm->saveType(DynamicRecord::fromArray($type, ['uuid' => 'D', 'title' => ['en' => 'T'], 'slug' => ['en' => 'd'], '_locales' => ['en', 'de']]), validate: false);

        $this->assertSame(['en', 'de'], $dm->findType('page', 'D')->availableLocales());
    }
}
