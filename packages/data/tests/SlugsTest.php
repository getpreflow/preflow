<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\Data\Model;
use Preflow\Data\Slugs;
use Preflow\Data\TypeRegistry;

#[Entity(table: 'pages', storage: 'sqlite')]
final class SlugPage extends Model
{
    #[Id]
    public int $id = 0;

    #[Field]
    public string $slug = '';
}

final class SlugsTest extends TestCase
{
    private DataManager $dm;
    private string $dir;

    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE jobs (uuid TEXT PRIMARY KEY, slug TEXT)');
        $pdo->exec('CREATE TABLE pages (id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT)');
        $this->dir = sys_get_temp_dir() . '/preflow_slugs_' . uniqid();
        mkdir($this->dir, 0755, true);
        file_put_contents($this->dir . '/job.json', json_encode([
            'key' => 'job', 'table' => 'jobs', 'storage' => 'sqlite',
            'fields' => ['slug' => ['type' => 'string']],
        ]));
        $driver = new SqliteDriver($pdo);
        $this->dm = new DataManager(['sqlite' => $driver, 'default' => $driver], typeRegistry: new TypeRegistry($this->dir));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    private function job(string $uuid, string $slug): void
    {
        $record = new DynamicRecord($this->dm->getTypeRegistry()->get('job'), ['uuid' => $uuid, 'slug' => $slug]);
        $this->dm->saveType($record);
    }

    public function testFreeSlugIsKept(): void
    {
        $this->assertSame('senior-developer', Slugs::unique($this->dm, 'job', 'Senior Developer'));
    }

    public function testCollisionsGetSuffixes(): void
    {
        $this->job('a', 'senior-developer');
        $this->assertSame('senior-developer-2', Slugs::unique($this->dm, 'job', 'Senior Developer'));
        $this->job('b', 'senior-developer-2');
        $this->assertSame('senior-developer-3', Slugs::unique($this->dm, 'job', 'Senior Developer'));
    }

    public function testExceptIdKeepsItsOwnSlug(): void
    {
        $this->job('a', 'senior-developer');
        $this->assertSame('senior-developer', Slugs::unique($this->dm, 'job', 'Senior Developer', exceptId: 'a'));
        $this->assertSame('senior-developer-2', Slugs::unique($this->dm, 'job', 'Senior Developer', exceptId: 'other'));
    }

    public function testExceptedRecordAmongTwoSharingTheSlug(): void
    {
        $this->job('a', 'senior-developer');
        $this->job('b', 'senior-developer');
        $this->assertSame('senior-developer-2', Slugs::unique($this->dm, 'job', 'Senior Developer', exceptId: 'a'));
        $this->assertSame('senior-developer-2', Slugs::unique($this->dm, 'job', 'Senior Developer', exceptId: 'b'));
    }

    public function testIntegerExceptId(): void
    {
        $page = new SlugPage();
        $page->slug = 'about';
        $this->dm->save($page);
        $this->assertSame('about', Slugs::unique($this->dm, SlugPage::class, 'About', exceptId: $page->id));
    }

    public function testTranslatableFieldIsRefused(): void
    {
        file_put_contents($this->dir . '/doc.json', json_encode([
            'key' => 'doc', 'table' => 'docs', 'storage' => 'sqlite',
            'fields' => ['slug' => ['type' => 'string', 'translatable' => true]],
        ]));
        $dm = new DataManager(['sqlite' => new SqliteDriver(new \PDO('sqlite::memory:'))], typeRegistry: new TypeRegistry($this->dir, new Locales(['en', 'de'], 'en')));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is translatable');
        Slugs::unique($dm, 'doc', 'x');
    }

    public function testModelClassAsType(): void
    {
        $page = new SlugPage();
        $page->slug = 'about';
        $this->dm->save($page);

        $this->assertSame('about-2', Slugs::unique($this->dm, SlugPage::class, 'About'));
        $this->assertSame('about', Slugs::unique($this->dm, SlugPage::class, 'About', exceptId: (string) $page->id));
    }

    public function testUmlauts(): void
    {
        $this->assertSame('mueller', Slugs::unique($this->dm, 'job', 'Müller'));
        $taken = [];
        $this->assertSame('strasse', Slugs::among('Straße', $taken));
    }

    public function testEmptyTextUsesTheFallback(): void
    {
        $this->assertSame('item', Slugs::unique($this->dm, 'job', '!!!'));
        $this->assertSame('page', Slugs::unique($this->dm, 'job', '', fallback: 'page'));
    }

    public function testMaxLengthWithAndWithoutSuffix(): void
    {
        $text = str_repeat('abcdefghi ', 12);
        $slug = Slugs::unique($this->dm, 'job', $text, max: 25);
        $this->assertLessThanOrEqual(25, strlen($slug));
        $this->assertSame('abcdefghi-abcdefghi', $slug); // cut at a hyphen

        $this->job('a', $slug);
        $second = Slugs::unique($this->dm, 'job', $text, max: 25);
        $this->assertSame('abcdefghi-abcdefghi-2', $second);

        $this->assertSame('abcdefghij', Slugs::unique($this->dm, 'job', 'abcdefghijklmnop', max: 10));
        $taken = ['abcdefghij' => true];
        $this->assertSame('abcdefgh-2', Slugs::among('abcdefghijklmnop', $taken, 10));
    }

    public function testCutNeverEndsInAHyphen(): void
    {
        $taken = [];
        $this->assertSame('abc', Slugs::among('abc-defgh', $taken, 4));
    }

    public function testUnknownFieldIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('has no field "permalink"');
        Slugs::unique($this->dm, 'job', 'x', field: 'permalink');
    }

    public function testUnknownTypeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Slugs::unique($this->dm, 'nope', 'x');
    }

    public function testAmongRecordsWhatItHandsOut(): void
    {
        $taken = ['news' => true];
        $this->assertSame('news-2', Slugs::among('News', $taken));
        $this->assertSame('news-3', Slugs::among('News', $taken));
        $this->assertSame(['news' => true, 'news-2' => true, 'news-3' => true], $taken);
    }
}
