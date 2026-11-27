<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Values;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\FrozenClock;
use Preflow\Data\ChangeDates;
use Preflow\Data\Condition;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Html\HtmlCleaner;
use Preflow\Data\Html\SymfonyHtmlCleaner;
use Preflow\Data\Locales;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Data\Tests\Fixtures\ModelBridge\RichNote;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\TypeRegistry;
use Preflow\Data\Values\Core\RichTextValue;
use Preflow\Testing\ContextFactory;

require_once __DIR__ . '/../Fixtures/ModelBridge/RichNote.php';

/**
 * Rich text is cleaned on every typed write in the core (0.93), before the driver
 * (DataManager::storageValues()): an API body, an import or a script saving through the
 * DataManager stores the same safe HTML Folio's form stores, and clean HTML keeps its bytes.
 */
final class RichTextOnWriteTest extends TestCase
{
    private const string UNSAFE = '<p onclick="steal()">Hello <strong>you</strong><script>alert(1)</script></p>';

    private const string CLEAN = '<p>Hello <strong>you</strong></p>';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/preflow_rich_text_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0755, true);
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->dir);
    }

    private function json(): DataManager
    {
        return new DataManager(['default' => new JsonFileDriver($this->dir . '/data')], 'default', new TypeRegistry($this->dir . '/models', new Locales(['en', 'de'], 'en'), [
            'rich_note' => RichNote::class,
        ]));
    }

    private function sqlite(): DataManager
    {
        $pdo = new \PDO('sqlite:' . $this->dir . '/db.sqlite');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $dm = new DataManager(['default' => new SqliteDriver($pdo)], 'default');
        (new RecordTableSync($dm))->sync(self::page());

        return $dm;
    }

    private static function page(): TypeDefinition
    {
        return new TypeDefinition('page', 'pages', 'default', [
            'title' => new TypeFieldDefinition('title'),
            'body' => new TypeFieldDefinition('body', 'richtext'),
            'intro' => new TypeFieldDefinition('intro', 'richtext', translatable: true),
        ], locales: new Locales(['en', 'de'], 'en'));
    }

    /** @return array<string, mixed> */
    private static function stored(DataManager $dm, string $id, string $table = 'pages'): array
    {
        $row = $dm->driver('default')->findOne($table, $id) ?? [];
        if (is_string($row['intro'] ?? null)) {
            $row['intro'] = json_decode($row['intro'], true);
        }

        return $row;
    }

    private static function at(string $at, \Closure $write): mixed
    {
        return Contexts::run(ContextFactory::forTest()->withClock(new FrozenClock(new \DateTimeImmutable($at))), $write);
    }

    private static function unsafePage(string $id = 'a'): DynamicRecord
    {
        return DynamicRecord::fromArray(self::page(), ['uuid' => $id, 'title' => 'T', 'body' => self::UNSAFE, 'intro' => ['en' => self::UNSAFE, 'de' => '<p>Hallo<img src=x onerror="steal()"></p>']]);
    }

    private function assertCleanPage(array $row): void
    {
        $this->assertSame(self::CLEAN, $row['body'] ?? null);
        $this->assertSame(self::CLEAN, $row['intro']['en'] ?? null, 'every locale of a translatable field');
        $this->assertStringNotContainsString('onerror', (string) ($row['intro']['de'] ?? ''));
        $this->assertStringStartsWith('<p>Hallo', (string) ($row['intro']['de'] ?? ''));
    }

    public function test_save_type_cleans_rich_text(): void
    {
        $dm = $this->json();
        $dm->saveType(self::unsafePage());

        $this->assertCleanPage(self::stored($dm, 'a'));
        $this->assertSame('T', self::stored($dm, 'a')['title'], 'a string field is no rich text: left as it is');
    }

    public function test_save_without_validation_cleans(): void
    {
        $dm = $this->json();
        $dm->saveType(self::unsafePage(), validate: false);

        $this->assertCleanPage(self::stored($dm, 'a'));
    }

    public function test_save_type_if_version_cleans(): void
    {
        $dm = $this->json();
        $this->assertTrue($dm->saveTypeIfVersion(self::unsafePage(), 0, validate: false));

        $this->assertCleanPage(self::stored($dm, 'a'));
        $this->assertSame(1, self::stored($dm, 'a')['_version']);
    }

    public function test_class_backed_type_cleans(): void
    {
        $dm = $this->json();
        $note = new RichNote();
        $note->body = self::UNSAFE;
        $dm->save($note, false);

        $this->assertSame(self::CLEAN, self::stored($dm, (string) $note->uuid, 'rich_note')['body']);
    }

    public function test_update_when_cleans(): void
    {
        $dm = $this->json();
        $def = self::page();
        $dm->saveType(DynamicRecord::fromArray($def, ['uuid' => 'a', 'title' => 'T', 'body' => '<p>old</p>']), false);

        $this->assertTrue($dm->updateWhen($def, 'a', Condition::all(), ['body' => self::UNSAFE, 'intro' => ['en' => self::UNSAFE, 'de' => '<p>Hallo<img src=x onerror="steal()"></p>']]));

        $this->assertCleanPage(self::stored($dm, 'a'));
    }

    public function test_resaving_clean_rich_text_keeps_bytes_and_change_date(): void
    {
        foreach (['json' => $this->json(), 'sqlite' => $this->sqlite()] as $driver => $dm) {
            $def = self::page();
            self::at('2026-02-01T10:00:00Z', static fn () => $dm->saveType(self::unsafePage(), false));
            $first = self::stored($dm, 'a');
            $this->assertSame('2026-02-01T10:00:00+00:00', $first[ChangeDates::FIELD], $driver);

            // Saved again by a path that hands the stored (clean) HTML back, and by one that hands the unsafe input again.
            self::at('2026-02-03T10:00:00Z', static fn () => $dm->saveType($dm->findIn($def, 'a'), false));
            $this->assertSame($first, self::stored($dm, 'a'), $driver . ': the stored HTML saved again');
            self::at('2026-02-04T10:00:00Z', static fn () => $dm->saveType(self::unsafePage(), false));

            $again = self::stored($dm, 'a');
            $this->assertSame($first, $again, $driver . ': same bytes, the change date kept');
            $this->assertSame('2026-02-01T10:00:00+00:00', $again[ChangeDates::FIELD], $driver);
        }
    }

    public function test_a_custom_cleaner_closure_replaces_the_default(): void
    {
        $dm = $this->json();
        $dm->htmlCleanerFrom(static fn (): HtmlCleaner => new class implements HtmlCleaner {
            public function clean(string $html): string
            {
                return 'cleaned:' . strip_tags($html);
            }
        });
        $dm->saveType(DynamicRecord::fromArray(self::page(), ['uuid' => 'a', 'body' => '<p>x</p>']), false);

        $this->assertSame('cleaned:x', self::stored($dm, 'a')['body']);
    }

    public function test_the_default_cleaner_is_built_once(): void
    {
        $dm = $this->json();
        $dm->htmlCleanerFrom(static fn (): ?HtmlCleaner => null);

        $this->assertInstanceOf(SymfonyHtmlCleaner::class, $dm->htmlCleaner(), 'a closure that gives none: the default');
        $this->assertSame($dm->htmlCleaner(), $dm->htmlCleaner());
        $this->assertInstanceOf(RichTextValue::class, $dm->fieldKinds()->valueOf(new TypeFieldDefinition('b', 'richtext')));
        $this->assertSame('rich_text', $dm->fieldKinds()->valueOf(new TypeFieldDefinition('b', 'richtext'))->shape(new TypeFieldDefinition('b', 'richtext')));
    }

    public function test_storage_values_cleans_for_importers(): void
    {
        $dm = $this->json();
        $row = $dm->storageValues(self::page(), ['uuid' => 'a', 'body' => self::UNSAFE, 'intro' => ['en' => self::UNSAFE]]);

        $this->assertSame(self::CLEAN, $row['body']);
        $this->assertSame(['en' => self::CLEAN], $row['intro']);
        $this->assertSame($row, $dm->storageValues(self::page(), $row), 'idempotent');
    }

    public function test_rich_text_value_cleans_on_every_step(): void
    {
        $value = new RichTextValue(static fn (): HtmlCleaner => SymfonyHtmlCleaner::standard());
        $field = new TypeFieldDefinition('b', 'richtext');

        $this->assertSame(self::CLEAN, $value->toStored(self::UNSAFE, $field));
        $this->assertSame(self::CLEAN, $value->toOutput(self::UNSAFE, $field), 'output is cleaned again, whatever is stored');
        $this->assertSame(['en' => self::CLEAN, 'de' => null], $value->clean(['en' => self::UNSAFE, 'de' => null], $field));
        $this->assertNull($value->clean(null, $field));
        $this->assertSame(42, $value->toStored(42, $field), 'other values pass through for the rules');
        $this->assertSame(['rich_text'], array_keys($value->shapes()));
    }

    public function test_nested_rich_text_is_cleaned_on_write_and_never_read_out(): void
    {
        // Final review finding 5: a locale value that is itself an array was passed through unclean.
        $value = new RichTextValue(static fn (): HtmlCleaner => SymfonyHtmlCleaner::standard());
        $field = new TypeFieldDefinition('b', 'richtext');

        $this->assertSame(['en' => ['x' => self::CLEAN, 'n' => 3]], $value->clean(['en' => ['x' => self::UNSAFE, 'n' => 3]], $field), 'every string at every depth');
        $this->assertSame([[self::CLEAN]], $value->toStored([[self::UNSAFE]], $field));
        // The output shape is cleaned HTML or null: what is no text reads as null.
        $this->assertNull($value->toOutput(['en' => self::UNSAFE], $field));
        $this->assertNull($value->toOutput(42, $field));
        $this->assertSame('', $value->toOutput('', $field));
    }
}
