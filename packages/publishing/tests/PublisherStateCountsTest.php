<?php

declare(strict_types=1);

namespace Preflow\Publishing\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Migration\MigrationRegistry;
use Preflow\Data\Observe\DataObserver;
use Preflow\Publishing\PublishingTypes;
use Preflow\Publishing\RecordWriter;
use Preflow\Publishing\SaveOptions;
use Preflow\Publishing\PublishState;
use Preflow\Publishing\Scheduler;
use Preflow\Publishing\ShadowTypes;

/**
 * states() and stateCounts() read only the working `_version` and the snapshot's
 * `_published_version` (@since 0.77): on every normal path they give what the full compare
 * (state()) gives, on JSON and on SQL storage; a snapshot from before 0.77 (no
 * `_published_version`) is compared in full.
 */
final class PublisherStateCountsTest extends PublishingAppTestCase
{
    private string $type = 'post';

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
        $this->write('config/models/article.json', (string) json_encode(['key' => 'article', 'table' => 'article', 'storage' => 'sqlite', 'publishing' => true, 'fields' => ['title' => ['type' => 'string'], 'views' => ['type' => 'number']]]));
        @mkdir($this->dir . '/storage', 0777, true);
    }

    /** @return array<string, array{string}> */
    public static function storages(): array
    {
        return ['json' => ['post'], 'sqlite' => ['article']];
    }

    #[DataProvider('storages')]
    public function test_counts_equal_the_full_compare_on_every_normal_path(string $type): void
    {
        $this->use($type);
        $p = $this->publisher();

        // Draft: never published.
        $p->saveDraft($this->record('draft', 'Draft'), null);
        // Published.
        $p->saveDraft($this->record('pub', 'Pub'), null);
        $p->publish($this->stored($type, 'pub'), 'ana', (int) $this->stored($type, 'pub')->get('_version'));
        // Edited after the publish.
        $p->publish($this->record('edit', 'One'), 'ana');
        $this->edit('edit', 'Two');
        // Published again after an edit.
        $p->publish($this->record('again', 'One'), 'ana');
        $this->edit('again', 'Two');
        $p->publish($this->stored($type, 'again'), 'ana', (int) $this->stored($type, 'again')->get('_version'));
        // Discarded after an edit.
        $p->publish($this->record('discard', 'One'), 'ana');
        $this->edit('discard', 'Two');
        $p->discard($type, 'discard');
        // Restored to the live revision, and to an older one.
        $p->publish($this->record('same', 'One'), 'ana');
        $p->publish($this->with('same', ['title' => $this->title('Two')]), 'ana');
        $p->restore($type, 'same', $p->revisions($type, 'same')[0]->id);
        $p->publish($this->record('older', 'One'), 'ana');
        $p->publish($this->with('older', ['title' => $this->title('Two')]), 'ana');
        $p->restore($type, 'older', $p->revisions($type, 'older')[1]->id);
        // Scheduled after an edit, then published by the scheduler.
        $p->publish($this->record('sched', 'One'), 'ana');
        $this->edit('sched', 'Two', ['_publish_at' => '2026-01-01T10:00']);
        $this->assertSame(PublishState::Changed, $p->states($type, ['sched'])['sched']);
        $c = $this->app()->container();
        $this->assertSame([$type . '/sched'], (new Scheduler($p, $c->get(PublishingTypes::class), $this->dm()))->runDue(new \DateTimeImmutable('2026-01-02 00:00'))['published']);
        // Scheduled without an edit.
        $p->publish($this->record('noedit', 'One'), 'ana');
        $this->dm()->saveType($this->with('noedit', ['_publish_at' => '2026-01-01T10:00']), false);
        (new Scheduler($p, $c->get(PublishingTypes::class), $this->dm()))->runDue(new \DateTimeImmutable('2026-01-02 00:00'));
        // Unpublished.
        $p->publish($this->record('off', 'One'), 'ana');
        $p->unpublish($type, 'off');

        $expected = [
            'draft' => PublishState::Draft, 'pub' => PublishState::Published, 'edit' => PublishState::Changed,
            'again' => PublishState::Published, 'discard' => PublishState::Published, 'same' => PublishState::Published,
            'older' => PublishState::Changed, 'sched' => PublishState::Published, 'noedit' => PublishState::Published,
            'off' => PublishState::Draft,
        ];
        $full = [];
        foreach ($this->dm()->queryType($type)->get()->items() as $working) {
            $full[(string) $working->getId()] = $p->state($working);
        }
        ksort($full);
        ksort($expected);
        $this->assertSame($expected, $full, 'the full compare');

        $states = $p->states($type);
        ksort($states);
        $this->assertSame($full, $states);
        $this->assertSame(['draft' => 2, 'published' => 6, 'changed' => 2], $p->stateCounts($type));
    }

    #[DataProvider('storages')]
    public function test_the_snapshot_keeps_the_version_it_was_published_from(string $type): void
    {
        $this->use($type);
        $p = $this->publisher();
        $p->publish($this->record('a', 'One'), 'ana');
        $this->edit('a', 'Two');
        $p->publish($this->stored($type, 'a'), 'ana', 2);

        $this->assertSame(2, (int) $this->stored($type, 'a')->get('_version'));
        $this->assertSame(2, (int) $p->snapshot($type, 'a')?->get('_published_version'));
        $this->assertSame(PublishState::Published, $p->state($this->stored($type, 'a')));
        $this->assertSame([], $p->changes($this->stored($type, 'a')), 'never content');
    }

    /** An app's own save (DataManager::saveType()) bumps the version (VersionFields), so the counts see the change. */
    #[DataProvider('storages')]
    public function test_an_app_save_bumps_the_version(string $type): void
    {
        $this->use($type);
        $p = $this->publisher();
        $p->publish($this->record('a', 'One'), 'ana');
        $app = $this->with('a', ['title' => $this->title('Elsewhere')]);
        $this->dm()->saveType($app, false);

        $this->assertSame(2, (int) $app->get('_version'), 'the record holds the version written');
        $this->assertSame(2, (int) $this->stored($type, 'a')->get('_version'));
        $this->assertSame(1, (int) $p->snapshot($type, 'a')?->get('_published_version'), 'snapshots keep theirs');
        $this->assertArrayNotHasKey('_version', array_filter($p->snapshot($type, 'a')?->toArray() ?? []), 'snapshots get no _version');
        $this->assertSame(['a' => PublishState::Changed], $p->states($type));
        $this->assertSame(PublishState::Changed, $p->state($this->stored($type, 'a')));
    }

    /** What they read is the two versions, not the content: a driver write past DataManager (keeping the version) is out of their reach. */
    #[DataProvider('storages')]
    public function test_counts_read_the_versions_not_the_content(string $type): void
    {
        $this->use($type);
        $p = $this->publisher();
        $p->publish($this->record('a', 'One'), 'ana');
        $def = $this->dm()->getTypeRegistry()->get($type);
        $row = $this->stored($type, 'a')->toArray();
        $row['title'] = $type === 'post' ? ['en' => 'Elsewhere'] : 'Elsewhere';
        $this->dm()->driver($def->storage)->save($def->table, 'a', $row, $def->idField);

        $this->assertSame(PublishState::Changed, $p->state($this->stored($type, 'a')));
        $this->assertSame(['a' => PublishState::Published], $p->states($type));
    }

    /** A snapshot written before 0.77 has no _published_version: that record is compared in full. */
    #[DataProvider('storages')]
    public function test_a_snapshot_without_the_version_is_compared_in_full(string $type): void
    {
        $this->use($type);
        $p = $this->publisher();
        foreach (['same', 'edited', 'newer'] as $id) {
            $p->publish($this->record($id, 'One'), 'ana');
        }
        $this->forgetPublishedVersion('same');
        $this->forgetPublishedVersion('edited');
        $this->dm()->saveType($this->with('edited', ['title' => $this->title('Two')]), false);
        $this->dm()->saveType($this->with('newer', ['title' => $this->title('Two')]), false);

        $this->assertSame(
            ['edited' => PublishState::Changed, 'newer' => PublishState::Changed, 'same' => PublishState::Published],
            self::sorted($p->states($type)),
        );
        $this->assertSame(['edited' => PublishState::Changed], $p->states($type, ['edited', 'missing']));
        $this->assertSame(['draft' => 0, 'published' => 1, 'changed' => 2], $p->stateCounts($type));
    }

    /** Saves through RecordWriter that change no content (a schedule, its cancel, the same text) keep a published record Published. */
    #[DataProvider('storages')]
    public function test_saves_without_a_content_change_stay_published(string $type): void
    {
        $this->use($type);
        $p = $this->publisher();
        $writer = $this->app()->container()->get(RecordWriter::class);
        $p->publish($this->record('a', 'One'), 'ana');

        $writer->save($this->with('a', []), new SaveOptions(by: 'ana', loadedVersion: 1, publishAt: '2099-01-01T10:00'));
        $this->assertSame('2099-01-01T10:00', $this->stored($type, 'a')->get('_publish_at'));
        $this->assertSame(2, (int) $this->stored($type, 'a')->get('_version'));
        $this->assertSame(2, (int) $p->snapshot($type, 'a')?->get('_published_version'), 'carried forward');
        $this->assertSame(['a' => PublishState::Published], $p->states($type), 'scheduled');

        $writer->save($this->with('a', []), new SaveOptions(by: 'ana', loadedVersion: 2, publishAt: ''));
        $this->assertSame('', (string) $this->stored($type, 'a')->get('_publish_at'));
        $this->assertSame(['a' => PublishState::Published], $p->states($type), 'cancelled');

        $writer->save($this->with('a', ['title' => $this->title('One')]), new SaveOptions(by: 'ana', loadedVersion: 3));
        $this->assertSame(['a' => PublishState::Published], $p->states($type), 'saved unchanged');

        $writer->save($this->with('a', ['title' => $this->title('Two')]), new SaveOptions(by: 'ana', loadedVersion: 4));
        $this->assertSame(['a' => PublishState::Changed], $p->states($type), 'changed');
        $writer->save($this->with('a', ['title' => $this->title('Two')]), new SaveOptions(by: 'ana', loadedVersion: 5));
        $this->assertSame(['a' => PublishState::Changed], $p->states($type), 'a change stays a change');
        $this->assertSame(PublishState::Changed, $p->state($this->stored($type, 'a')));
    }

    /** A new position and a new share token are written without a new version: the state stays. */
    #[DataProvider('storages')]
    public function test_system_keys_keep_the_version(string $type): void
    {
        $this->use($type);
        $p = $this->publisher();
        $p->publish($this->record('a', 'One'), 'ana');
        $p->saveSystemKeys($type, 'a', ['_order' => 7, '_share_token' => 'abc']);
        $p->syncOrder($this->stored($type, 'a'));

        $this->assertSame(1, (int) $this->stored($type, 'a')->get('_version'));
        $this->assertSame('abc', $this->stored($type, 'a')->get('_share_token'));
        $this->assertSame(7, (int) $p->snapshot($type, 'a')?->get('_order'));
        $this->assertSame(['a' => PublishState::Published], $p->states($type));
        $this->expectException(\InvalidArgumentException::class);
        $p->saveSystemKeys($type, 'a', ['title' => 'x']);
    }

    /** A publish landing between the mark's read and its write keeps the version it wrote. */
    #[DataProvider('storages')]
    public function test_a_publish_between_the_marks_read_and_write_wins(string $type): void
    {
        $this->use($type);
        $p = $this->publisher();
        $p->publish($this->record('a', 'One'), 'ana');
        $this->forgetPublishedVersion('a');
        $this->edit('a', 'Two');
        $table = $this->dm()->getTypeRegistry()->get($type)->table . '__published';
        $reads = 0;
        $this->dm()->observe(new class ($table, $reads, fn () => $p->publish($this->stored($type, 'a'), 'bo', 2)) implements DataObserver {
            public function __construct(private string $table, private int &$reads, private \Closure $publish) {}

            public function read(string $storage, string $type, ?string $id): void
            {
                // The mark reads the published versions, the working copies, then the snapshots: publish after that.
                if ($type === $this->table && ++$this->reads === 2) {
                    ($this->publish)();
                }
            }

            public function wrote(string $storage, string $type, ?string $id): void {}

            public function rawRead(string $storage, string $sql): void {}

            public function rawWrote(string $storage, string $sql): void {}
        });

        $this->assertSame(0, $p->markPublishedVersions($type), 'the mark lost the race');
        $this->dm()->observe(null);
        $this->assertGreaterThanOrEqual(2, $reads);
        $this->assertSame(2, (int) $p->snapshot($type, 'a')?->get('_published_version'));
        $this->assertSame('Two', $this->plain($p->snapshot($type, 'a')?->get('title')));
        $this->assertSame(['a' => PublishState::Published], $p->states($type));
    }

    /** Orphan snapshots (their working copy gone) are marked -1, so they are not read again. */
    public function test_orphan_snapshots_are_marked_once(): void
    {
        $this->use('article');
        $p = $this->publisher();
        $p->publish($this->record('a', 'One'), 'ana');
        $this->forgetPublishedVersion('a');
        $this->dm()->execute("DELETE FROM article WHERE uuid = 'a'", [], 'sqlite');

        $this->assertSame(1, $p->markPublishedVersions('article'));
        $this->assertSame(-1, (int) $p->snapshot('article', 'a')?->get('_published_version'));
        $this->assertSame(0, $p->markPublishedVersions('article'));
    }

    /** Tables not migrated yet (no _published_version column): publishing works, the states are the full compare. */
    public function test_without_the_column_reads_compare_in_full_and_writes_leave_it_out(): void
    {
        $this->use('article');
        $this->dm()->execute('ALTER TABLE article__published DROP COLUMN _published_version', [], 'sqlite');
        $p = $this->publisher();
        $p->publish($this->record('a', 'One'), 'ana');
        $p->publish($this->record('b', 'One'), 'ana');
        $this->edit('b', 'Two');
        $p->saveSystemKeys('article', 'a', ['_order' => 3]);
        $p->syncOrder($this->stored('article', 'a'));
        $p->discard('article', 'b');
        $this->edit('a', 'Two');

        $this->assertSame(['a' => PublishState::Changed, 'b' => PublishState::Published], self::sorted($p->states('article')));
        $this->assertSame(3, (int) $p->snapshot('article', 'a')?->get('_order'));
        $this->assertSame(0, $p->markPublishedVersions('article'));
    }

    /** A publish that fails in the scheduler keeps its schedule, and the run goes on with the next record. */
    #[DataProvider('storages')]
    public function test_a_failed_scheduled_publish_keeps_its_schedule(string $type): void
    {
        $this->use($type);
        $p = $this->publisher();
        foreach (['a', 'b'] as $id) {
            $p->saveDraft($this->record($id, 'One'), null);
            $this->edit($id, 'One', ['_publish_at' => '2026-01-01T10:00']);
        }
        $p->publish($this->stored($type, 'b'), 'ana', 2);
        $this->edit('b', 'Two', ['_publish_at' => '2026-01-01T10:00']);
        // The revisions of the type cannot be written: every publish fails after the working copy.
        $revisions = $this->dm()->getTypeRegistry()->get($type)->table . '__revisions';
        if ($type === 'article') {
            $this->dm()->execute("DROP TABLE {$revisions}", [], 'sqlite');
        } else {
            @mkdir($this->dir . '/storage/data/' . $revisions, 0777, true);
            chmod($this->dir . '/storage/data/' . $revisions, 0500);
        }
        set_error_handler(static fn (): bool => true); // the JSON driver's own warning about the folder
        try {
            $run = (new Scheduler($p, $this->app()->container()->get(PublishingTypes::class), $this->dm()))->runDue(new \DateTimeImmutable('2026-01-02 00:00'));
        } finally {
            restore_error_handler();
            if ($type === 'post') {
                chmod($this->dir . '/storage/data/' . $revisions, 0777);
            }
        }

        $this->assertSame([], $run['published']);
        $this->assertSame([$type . '/a', $type . '/b'], array_keys($run['failed']), 'both tried, both failed');
        foreach (['a', 'b'] as $id) {
            $this->assertSame('2026-01-01T10:00', $this->stored($type, $id)->get('_publish_at'), "{$id} keeps its schedule");
        }
    }

    /** The migrate step gives snapshots from before 0.77 their version, once; the states stay those of the full compare. */
    #[DataProvider('storages')]
    public function test_old_snapshots_are_marked_with_their_version_once(string $type): void
    {
        $this->use($type);
        $p = $this->publisher();
        foreach (['same', 'edited'] as $id) {
            $p->publish($this->record($id, 'One'), 'ana');
            $this->edit($id, 'Two');
            $p->publish($this->stored($type, $id), 'ana', 2);
            $this->forgetPublishedVersion($id);
        }
        $p->saveDraft($this->record('draft', 'One'), null);
        $this->dm()->saveType($this->with('edited', ['title' => $this->title('Three')]), false);

        $steps = array_keys($this->app()->container()->get(MigrationRegistry::class)->steps());
        $this->assertSame(array_search('publishing.tables', $steps, true) + 1, array_search('publishing.published_versions', $steps, true), 'right after the tables');
        $this->assertSame(["marked 2 published copies of {$type} with their version"], $this->app()->container()->get(MigrationRegistry::class)->steps()['publishing.published_versions']());
        $this->assertSame(0, $p->markPublishedVersions($type), 'a second run marks nothing');

        $this->assertSame(2, (int) $p->snapshot($type, 'same')?->get('_published_version'));
        $this->assertSame(-1, (int) $p->snapshot($type, 'edited')?->get('_published_version'));
        $this->assertSame('Two', $this->plain($p->snapshot($type, 'edited')?->get('title')), 'the snapshot keeps its content');
        $this->assertSame(['draft' => PublishState::Draft, 'edited' => PublishState::Changed, 'same' => PublishState::Published], self::sorted($p->states($type)));

        $p->discard($type, 'edited');
        $this->assertSame(PublishState::Published, $p->states($type, ['edited'])['edited']);
    }

    #[DataProvider('storages')]
    public function test_states_of_some_ids_and_of_none(string $type): void
    {
        $this->use($type);
        $p = $this->publisher();
        $p->publish($this->record('a', 'One'), 'ana');
        $p->saveDraft($this->record('b', 'One'), null);

        $this->assertSame(['a' => PublishState::Published], $p->states($type, ['a', 'missing']));
        $this->assertSame([], $p->states($type, []));
        $this->assertSame(['draft' => 1, 'published' => 1, 'changed' => 0], $p->stateCounts($type));
    }

    public function test_an_empty_type_counts_nothing(): void
    {
        $this->assertSame(['draft' => 0, 'published' => 0, 'changed' => 0], $this->publisher()->stateCounts('post'));
    }

    private function use(string $type): void
    {
        $this->type = $type;
        // The SQLite tables (also for the JSON runs: the scheduler goes through every publishing type).
        $step = $this->app()->container()->get(MigrationRegistry::class)->steps()['publishing.tables'] ?? throw new \RuntimeException('step not registered');
        $step();
    }

    private function title(string $text): mixed
    {
        return $this->type === 'post' ? ['en' => $text] : $text;
    }

    private function plain(mixed $title): mixed
    {
        $title = is_string($title) && str_starts_with($title, '{') ? json_decode($title, true) : $title;

        return is_array($title) ? ($title['en'] ?? null) : $title;
    }

    private function record(string $id, string $title): DynamicRecord
    {
        return DynamicRecord::fromArray($this->dm()->getTypeRegistry()->get($this->type), ['uuid' => $id, 'title' => $this->title($title)]);
    }

    /** @param array<string, mixed> $values the stored working copy with these values set */
    private function with(string $id, array $values): DynamicRecord
    {
        $working = $this->stored($this->type, $id);
        foreach ($values as $key => $value) {
            $working->set($key, $value);
        }

        return $working;
    }

    /** @param array<string, mixed> $more */
    private function edit(string $id, string $title, array $more = []): void
    {
        $working = $this->with($id, ['title' => $this->title($title)] + $more);
        $this->publisher()->saveDraft($working, (int) $working->get('_version'));
    }

    /** As a snapshot published before 0.77 is stored. */
    private function forgetPublishedVersion(string $id): void
    {
        $shadow = ShadowTypes::published($this->dm()->getTypeRegistry()->get($this->type));
        if ($this->type === 'article') {
            $this->dm()->execute('UPDATE article__published SET _published_version = NULL WHERE uuid = ?', [$id], 'sqlite');

            return;
        }
        $data = ($this->dm()->findIn($shadow, $id) ?? throw new \RuntimeException('no snapshot'))->toArray();
        unset($data['_published_version']);
        $this->dm()->deleteIn($shadow, $id);
        $this->dm()->saveType(DynamicRecord::fromArray($shadow, $data), false);
        $this->assertNull($this->dm()->findIn($shadow, $id)?->get('_published_version'));
    }

    /**
     * @param array<string, PublishState> $states
     * @return array<string, PublishState>
     */
    private static function sorted(array $states): array
    {
        ksort($states);

        return $states;
    }
}
