<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Values;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Data\Tests\Fixtures\ModelBridge\RichNote;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\TypeRegistry;

require_once __DIR__ . '/../Fixtures/ModelBridge/RichNote.php';

/**
 * After a typed write the caller's record holds what was stored (0.93.1): the rich text the core
 * cleaned is put back on it (every locale of a translatable field), and nothing else on it
 * changes. So a caller that saves and then compares or publishes the same object sees the stored
 * values, on every driver.
 */
final class RichTextSavedRecordTest extends TestCase
{
    /** Safe, but not in cleaned form. */
    private const string RAW = "<p>it's<br></p>";

    private const string CLEAN = '<p>it&#039;s<br /></p>';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/preflow_rich_saved_' . bin2hex(random_bytes(4));
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

    /** @return array<string, array{string}> */
    public static function storages(): array
    {
        return ['JSON' => ['json'], 'SQLite' => ['sqlite']];
    }

    private function dm(string $storage): DataManager
    {
        if ($storage === 'json') {
            return new DataManager(['default' => new JsonFileDriver($this->dir . '/data')], 'default', new TypeRegistry($this->dir . '/models', new Locales(['en', 'de'], 'en'), [
                'rich_note' => RichNote::class,
            ]));
        }
        $pdo = new \PDO('sqlite:' . $this->dir . '/db.sqlite');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $dm = new DataManager(['default' => new SqliteDriver($pdo)], 'default');
        (new RecordTableSync($dm))->sync(self::page());
        // A key that is no field, and the version saveTypeIfVersion() writes.
        $pdo->exec('ALTER TABLE pages ADD COLUMN extra TEXT');
        $pdo->exec('ALTER TABLE pages ADD COLUMN _version INTEGER');

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

    private static function rawPage(): DynamicRecord
    {
        return DynamicRecord::fromArray(self::page(), ['uuid' => 'a', 'title' => "it's", 'body' => self::RAW, 'intro' => ['en' => self::RAW, 'de' => "<p>c'est<br></p>"], 'extra' => "<b>it's</b>"]);
    }

    /** @return array<string, mixed> the intro as a map (SQLite hands it back as JSON text) */
    private static function intro(mixed $intro): array
    {
        return is_string($intro) ? (array) json_decode($intro, true) : (array) $intro;
    }

    private function assertHoldsStored(DynamicRecord $record): void
    {
        $this->assertSame(self::CLEAN, $record->get('body'));
        $this->assertSame(['en' => self::CLEAN, 'de' => '<p>c&#039;est<br /></p>'], $record->get('intro'), 'every locale of a translatable field');
        $this->assertSame("it's", $record->get('title'), 'a field that is no rich text: as given');
        $this->assertSame("<b>it's</b>", $record->get('extra'), 'a key that is no field: as given');
    }

    #[DataProvider('storages')]
    public function test_save_type_puts_the_cleaned_values_on_the_record(string $storage): void
    {
        $dm = $this->dm($storage);
        $validated = self::rawPage();
        $dm->saveType($validated);
        $this->assertHoldsStored($validated);

        $unvalidated = self::rawPage();
        $dm->saveType($unvalidated, false);
        $this->assertHoldsStored($unvalidated);
        $stored = $dm->findIn(self::page(), 'a') ?? $this->fail('stored');
        $this->assertSame($stored->get('body'), $unvalidated->get('body'));
        $this->assertSame(self::intro($stored->get('intro')), $unvalidated->get('intro'));
    }

    #[DataProvider('storages')]
    public function test_save_type_if_version_puts_the_cleaned_values_on_the_record(string $storage): void
    {
        $dm = $this->dm($storage);
        $record = self::rawPage();
        $this->assertTrue($dm->saveTypeIfVersion($record, 0, validate: false));

        $this->assertHoldsStored($record);
        $this->assertSame(1, $record->get('_version'));
    }

    #[DataProvider('storages')]
    public function test_a_stored_record_saved_again_keeps_its_values_as_read(string $storage): void
    {
        $dm = $this->dm($storage);
        $dm->saveType(self::rawPage(), false);
        $read = $dm->findIn(self::page(), 'a') ?? $this->fail('stored');
        $before = $read->toArray();

        $dm->saveType($read, false);

        $this->assertSame($before['body'], $read->get('body'));
        $this->assertSame($before['intro'], $read->get('intro'), 'JSON text from SQL stays JSON text, a map stays a map');
    }

    public function test_a_failed_version_check_leaves_the_record_as_given(): void
    {
        $dm = $this->dm('json');
        $dm->saveTypeIfVersion(self::rawPage(), 0, validate: false);
        $stale = self::rawPage();

        $this->assertFalse($dm->saveTypeIfVersion($stale, 0, validate: false));

        $this->assertSame(self::RAW, $stale->get('body'), 'nothing was written: nothing put back');
    }

    public function test_a_class_backed_model_holds_the_cleaned_value(): void
    {
        $dm = $this->dm('json');
        $note = new RichNote();
        $note->body = self::RAW;
        $dm->save($note, false);

        $this->assertSame(self::CLEAN, $note->body);
    }
}
