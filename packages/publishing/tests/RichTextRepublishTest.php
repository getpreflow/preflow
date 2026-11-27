<?php

declare(strict_types=1);

namespace Preflow\Publishing\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\FrozenClock;
use Preflow\Data\ChangeDates;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Migration\MigrationRegistry;
use Preflow\Publishing\PublishState;
use Preflow\Testing\ContextFactory;

/**
 * Since 0.93.0 every typed write cleans rich text. A publish compares the working copy with the
 * stored one: both as cleaned (0.93.1), so HTML that is safe but not in its cleaned form (an
 * apostrophe, "<br>") counts as a change only when the cleaned text differs. A site's importer
 * that saves and then publishes the same record keeps the version, the change date and the
 * revision's changed fields when nothing changed.
 */
final class RichTextRepublishTest extends PublishingAppTestCase
{
    /** Safe input not in cleaned form; stored as CLEAN. */
    private const string RAW = "<p>it's<br></p>";

    /** The same text in another uncleaned form. */
    private const string RAW_TOO = "<p>it's<br/></p>";

    private const string CLEAN = '<p>it&#039;s<br /></p>';

    protected function setUp(): void
    {
        parent::setUp();
        $fixtures = var_export(__DIR__ . '/Fixtures', true);
        $this->write('config/data.php', <<<PHP
            <?php
            return [
                'drivers' => [
                    'json' => ['driver' => \\Preflow\\Data\\Driver\\JsonFileDriver::class, 'path' => __DIR__ . '/../storage/data'],
                    'sqlite' => ['path' => __DIR__ . '/../storage/app.sqlite'],
                ],
                'default' => 'json',
                'models_path' => __DIR__ . '/models',
                'model_paths' => [{$fixtures}],
            ];
            PHP);
        @mkdir($this->dir . '/storage', 0777, true);
        foreach (['article' => 'json', 'sarticle' => 'sqlite'] as $key => $storage) {
            $this->write('config/models/' . $key . '.json', (string) json_encode(['key' => $key, 'table' => $key, 'storage' => $storage, 'publishing' => true, 'fields' => [
                'title' => ['type' => 'string'],
                'body' => ['type' => 'richtext'],
                'intro' => ['type' => 'richtext', 'translatable' => true],
            ]]));
        }
    }

    /** @return array<string, array{string}> */
    public static function storages(): array
    {
        return ['JSON' => ['article'], 'SQLite' => ['sarticle']];
    }

    private function prepare(string $type): void
    {
        if ($type === 'sarticle') {
            $step = $this->app()->container()->get(MigrationRegistry::class)->steps()['publishing.tables'] ?? throw new \RuntimeException('step not registered');
            $step();
        }
    }

    private static function at(string $at, \Closure $write): mixed
    {
        return Contexts::run(ContextFactory::forTest()->withClock(new FrozenClock(new \DateTimeImmutable($at))), $write);
    }

    /** @param array<string, mixed> $values */
    private function record(string $type, array $values = []): DynamicRecord
    {
        return DynamicRecord::fromArray($this->dm()->getTypeRegistry()->get($type), ['uuid' => 'a1', 'title' => 'T', 'body' => self::RAW, 'intro' => ['en' => self::RAW, 'de' => "<p>c'est<br></p>"], ...$values]);
    }

    /** @return array<string, mixed> */
    private function intro(DynamicRecord $record): array
    {
        $intro = $record->get('intro');

        return is_string($intro) ? (array) json_decode($intro, true) : (array) $intro;
    }

    #[DataProvider('storages')]
    public function test_saving_and_publishing_the_same_rich_text_twice_changes_nothing(string $type): void
    {
        $this->prepare($type);
        $import = function () use ($type): void {
            $record = $this->record($type);
            $this->dm()->saveType($record, false);
            $this->publisher()->publish($record, 'import');
        };

        self::at('2026-03-01T08:00:00Z', $import);
        $first = $this->stored($type, 'a1');
        $firstLive = $this->publisher()->snapshot($type, 'a1');
        self::at('2026-03-02T08:00:00Z', $import);
        $second = $this->stored($type, 'a1');
        $secondLive = $this->publisher()->snapshot($type, 'a1');

        $this->assertSame(self::CLEAN, $second->get('body'));
        $this->assertSame(['en' => self::CLEAN, 'de' => '<p>c&#039;est<br /></p>'], $this->intro($second));
        $this->assertSame((int) $first->get('_version'), (int) $second->get('_version'), 'same content again: same version');
        $this->assertSame($first->get(ChangeDates::FIELD), $second->get(ChangeDates::FIELD), 'same content again: same change date');
        $this->assertSame($firstLive?->get(ChangeDates::FIELD), $secondLive?->get(ChangeDates::FIELD), 'the published copy keeps its change date');
        $revisions = $this->publisher()->revisions($type, 'a1');
        $this->assertCount(2, $revisions, 'every publish appends a revision (as since 0.75)');
        $this->assertSame([], $revisions[0]->changed, 'the second publish changed no field');
        $this->assertSame(self::CLEAN, $revisions[0]->data['body'], 'the revision holds the stored text');
        $this->assertSame(PublishState::Published, $this->publisher()->state($second));
    }

    #[DataProvider('storages')]
    public function test_a_publish_without_a_save_of_the_same_text_uncleaned_changes_nothing(string $type): void
    {
        $this->prepare($type);
        self::at('2026-03-01T08:00:00Z', fn () => $this->publisher()->publish($this->record($type), 'import'));
        $first = $this->stored($type, 'a1');
        $firstLive = $this->publisher()->snapshot($type, 'a1');

        // Built in PHP, never saved in this request: the same text in another uncleaned form.
        $again = $this->record($type, ['body' => self::RAW_TOO, 'intro' => ['en' => self::RAW_TOO, 'de' => "<p>c'est<br /></p>"]]);
        $this->assertSame(PublishState::Published, $this->publisher()->state($again), 'compared as stored');
        $this->assertSame([], $this->publisher()->changes($again));
        self::at('2026-03-02T08:00:00Z', fn () => $this->publisher()->publish($again, 'import'));
        $second = $this->stored($type, 'a1');

        $this->assertSame((int) $first->get('_version'), (int) $second->get('_version'));
        // (The working copy's own date follows the date the record is given with, as since 0.84: this
        // one has none. A record read or saved first holds the stored one, see above.)
        $this->assertSame($firstLive?->get(ChangeDates::FIELD), $this->publisher()->snapshot($type, 'a1')?->get(ChangeDates::FIELD), 'the published copy keeps its change date');
        $this->assertSame([], $this->publisher()->revisions($type, 'a1')[0]->changed);
        $this->assertSame(PublishState::Published, $this->publisher()->state($second));
        $this->assertSame(self::CLEAN, $again->get('body'), 'the record holds what was stored');
    }

    #[DataProvider('storages')]
    public function test_a_real_change_still_counts(string $type): void
    {
        $this->prepare($type);
        self::at('2026-03-01T08:00:00Z', function () use ($type): void {
            $record = $this->record($type);
            $this->dm()->saveType($record, false);
            $this->publisher()->publish($record, 'import');
        });
        $first = $this->stored($type, 'a1');

        $changed = $this->record($type, ['intro' => ['en' => self::RAW, 'de' => "<p>c'est neuf<br></p>"]]);
        $this->assertSame(PublishState::Changed, $this->publisher()->state($changed));
        $this->assertSame(['intro'], $this->publisher()->changes($changed));
        self::at('2026-03-02T08:00:00Z', fn () => $this->publisher()->publish($changed, 'import'));
        $second = $this->stored($type, 'a1');

        $this->assertSame((int) $first->get('_version') + 1, (int) $second->get('_version'));
        $this->assertSame('2026-03-02T08:00:00+00:00', $second->get(ChangeDates::FIELD));
        $this->assertSame(['intro'], $this->publisher()->revisions($type, 'a1')[0]->changed);
        $this->assertSame('<p>c&#039;est neuf<br /></p>', $this->intro($second)['de']);
    }

    public function test_a_draft_save_of_the_published_text_uncleaned_stays_published(): void
    {
        $this->publisher()->publish($this->record('article'), 'import');
        $draft = $this->record('article', ['body' => self::RAW_TOO]);
        $draft->set('_version', $this->stored('article', 'a1')->get('_version'));

        $this->publisher()->saveDraft($draft, (int) $draft->get('_version'));

        $this->assertSame(self::CLEAN, $draft->get('body'));
        $this->assertSame(PublishState::Published, $this->publisher()->state($this->stored('article', 'a1')));
        $this->assertSame(['a1' => PublishState::Published], $this->publisher()->states('article'));
    }

    public function test_rows_stored_uncleaned_before_0_93_compare_equal_to_the_same_text(): void
    {
        // Published before 0.93: working copy and published copy hold the text as given.
        $this->publisher()->publish($this->record('article', ['body' => '<p>plain</p>', 'intro' => ['en' => '<p>plain</p>']]), null);
        $def = $this->dm()->getTypeRegistry()->get('article');
        foreach ([$def->table, $def->table . '__published'] as $table) {
            $row = $this->dm()->driver('json')->findOne($table, 'a1') ?? $this->fail($table);
            $this->dm()->driver('json')->save($table, 'a1', ['body' => self::RAW, 'intro' => ['en' => self::RAW]] + $row);
        }
        $first = $this->stored('article', 'a1');
        $this->assertSame(['a1' => PublishState::Published], $this->publisher()->states('article'));

        // An import after the upgrade: the same text, saved (cleaned) and published.
        $record = $this->record('article', ['intro' => ['en' => self::RAW]]);
        $this->dm()->saveType($record, false);
        $this->assertSame(PublishState::Published, $this->publisher()->state($this->stored('article', 'a1')), 'a cleaned working copy against an uncleaned published copy');
        $this->publisher()->publish($record, null);

        $this->assertSame((int) $first->get('_version'), (int) $this->stored('article', 'a1')->get('_version'));
        $this->assertSame([], $this->publisher()->revisions('article', 'a1')[0]->changed);
    }
}
