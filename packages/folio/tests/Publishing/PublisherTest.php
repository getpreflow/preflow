<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Publishing;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\RecordValidator;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\Types\StringFieldType;
use Preflow\Folio\Field\Types\TextFieldType;
use Preflow\Folio\Publishing\PublishState;
use Preflow\Folio\Publishing\Publisher;
use Preflow\Folio\Publishing\VersionConflict;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidatorFactory;

final class PublisherTest extends TestCase
{
    private string $dir;
    private DataManager $dm;
    private TypeRegistry $registry;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pub_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0777, true);
        $fields = [
            'title' => ['type' => 'string', 'validate' => ['required']],
            'body' => ['type' => 'text'],
        ];
        $this->model('post', ['frontend' => ['route' => '/{slug}'], 'fields' => $fields]);
        $this->model('memo', ['fields' => $fields]);
        $this->model('memo2', ['publishing' => true, 'fields' => $fields]);
        $this->model('post2', ['frontend' => ['route' => '/x/{slug}'], 'publishing' => false, 'fields' => $fields]);
        $this->model('site', ['singleton' => true, 'fields' => $fields]);

        $this->registry = new TypeRegistry($this->dir . '/models');
        $this->dm = new DataManager(
            drivers: ['json' => new JsonFileDriver($this->dir . '/store')],
            defaultDriver: 'json',
            typeRegistry: $this->registry,
            validatorFactory: new ValidatorFactory(new RuleFactory()),
        );
        $this->now = new \DateTimeImmutable('2026-10-20T10:00:00+00:00');
    }

    protected function tearDown(): void
    {
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($rii as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    public function test_publishing_defaults(): void
    {
        $p = $this->publisher();

        $this->assertTrue($p->enabled('post'));
        $this->assertFalse($p->enabled('memo'));
        $this->assertTrue($p->enabled('memo2'));
        $this->assertTrue($p->enabled('site'));
        $this->assertFalse($p->enabled('post2'));
    }

    public function test_states_follow_content(): void
    {
        $p = $this->publisher();
        $post = $this->working('post', ['title' => 'A']);

        $this->assertSame(PublishState::Draft, $p->state($post));

        $p->publish($post, null);
        $this->assertSame(PublishState::Published, $p->state($this->reload('post')));

        $changed = $this->reload('post');
        $changed->set('title', 'B');
        $p->saveDraft($changed, null);
        $this->assertSame(PublishState::Changed, $p->state($this->reload('post')));

        $p->discard('post', 'r1');
        $this->assertSame(PublishState::Published, $p->state($this->reload('post')));
        $this->assertSame('A', $this->reload('post')->get('title'));
    }

    public function test_meta_keys_do_not_change_state(): void
    {
        $p = $this->publisher();
        $p->publish($this->working('post', ['title' => 'A']), null);

        $post = $this->reload('post');
        $post->set('_publish_at', '2026-11-01T09:00');
        $post->set('_order', 30);
        $post->set('_share_token', str_repeat('a', 32));
        $p->saveDraft($post, null);

        $this->assertSame(PublishState::Published, $p->state($this->reload('post')));
    }

    public function test_missing_and_empty_values_are_the_same_content(): void
    {
        $p = $this->publisher();
        $p->publish($this->working('post', ['title' => 'A']), null); // no body key, as seeded
        $post = $this->reload('post');
        $post->set('body', ''); // what a form save stores for an untouched empty field
        $p->saveDraft($post, null);

        $this->assertSame(PublishState::Published, $p->state($this->reload('post')));

        $post = $this->reload('post');
        $post->set('title', 'B');
        $p->publish($post, null);
        $this->assertSame(['title'], $p->revisions('post', 'r1')[0]->changed);
    }

    public function test_cleared_schedule_survives_null_dropping_storage(): void
    {
        // PdoDriver drops null values, and MySQL's upsert keeps the old column value.
        $inner = new JsonFileDriver($this->dir . '/store');
        $this->dm = new DataManager(
            drivers: ['json' => new NullDroppingDriver($inner)],
            defaultDriver: 'json',
            typeRegistry: $this->registry,
            validatorFactory: new ValidatorFactory(new RuleFactory()),
        );
        $p = $this->publisher();
        $this->dm->saveType($this->working('post', ['title' => 'A', '_publish_at' => '2026-10-20T09:00']), false);

        $p->publish($this->reload('post'), 'scheduler');

        $due = (new \Preflow\Folio\Publishing\Scheduler($p, new TypeCatalog($this->dir . '/models'), $this->dm))
            ->runDue(new \DateTimeImmutable('2026-10-20T10:00'));
        $this->assertSame([], $due['published']);
    }

    public function test_publish_stamps_snapshot(): void
    {
        $p = $this->publisher();
        $p->publish($this->working('post', ['title' => 'A', '_publish_at' => '2026-10-21T09:00']), 'u@x');

        $snapshot = $p->snapshot('post', 'r1');
        $this->assertSame($this->now->format(DATE_ATOM), $snapshot?->get('_published_at'));
        $this->assertSame('u@x', $snapshot?->get('_published_by'));
        $this->assertEmpty($this->reload('post')->get('_publish_at'));
    }

    public function test_publish_validates_fully_but_draft_does_not(): void
    {
        $p = $this->publisher();
        $post = $this->working('post', ['title' => '']);
        $p->saveDraft($post, null);
        $this->assertSame('', $this->reload('post')->get('title'));

        try {
            $p->publish($this->reload('post'), null);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('title', $e->errors());
        }
        $this->assertNull($p->snapshot('post', 'r1'));
    }

    public function test_unpublish_removes_snapshot(): void
    {
        $p = $this->publisher();
        $p->publish($this->working('post', ['title' => 'A']), null);
        $p->unpublish('post', 'r1');

        $this->assertNull($p->snapshot('post', 'r1'));
        $this->assertSame(PublishState::Draft, $p->state($this->reload('post')));
    }

    public function test_version_conflict(): void
    {
        $p = $this->publisher();
        $this->dm->saveType($this->working('post', ['title' => 'A', '_version' => 3]), false);

        try {
            $p->saveDraft($this->reload('post'), 2);
            $this->fail('expected VersionConflict');
        } catch (VersionConflict $e) {
            $this->assertSame(3, $e->current);
        }

        $p->saveDraft($this->reload('post'), 3);
        $this->assertSame(4, $this->reload('post')->get('_version'));

        $p->saveDraft($this->reload('post'), null);
        $this->assertSame(5, $this->reload('post')->get('_version'));
    }

    public function test_live_and_query_read_snapshots_only_when_enabled(): void
    {
        $p = $this->publisher();
        $p->saveDraft($this->working('post', ['title' => 'Draft']), null);
        $p->saveDraft($this->working('memo', ['title' => 'Memo']), null);

        $this->assertNull($p->live('post', 'r1'));
        $this->assertSame(0, $p->query('post')->get()->total());
        $this->assertSame('Memo', $p->live('memo', 'r1')?->get('title'));
        $this->assertSame(1, $p->query('memo')->get()->total());

        $p->publish($this->reload('post'), null);
        $this->assertSame('Draft', $p->live('post', 'r1')?->get('title'));
        $this->assertSame(1, $p->query('post')->get()->total());
    }

    public function test_publish_appends_revision_with_changed_fields(): void
    {
        $p = $this->publisher();
        $p->publish($this->working('post', ['title' => 'A']), 'u@x');
        $post = $this->reload('post');
        $post->set('body', 'More');
        $p->publish($post, 'u@x');

        $revisions = $p->revisions('post', 'r1');
        $this->assertCount(2, $revisions);
        $this->assertSame(['body'], $revisions[0]->changed);
        $this->assertSame(['title'], $revisions[1]->changed);
        $this->assertSame('u@x', $revisions[0]->createdBy);
        $this->assertSame('More', $revisions[0]->data['body']);
    }

    public function test_revisions_are_capped(): void
    {
        $p = $this->publisher(keep: 3);
        for ($i = 1; $i <= 5; $i++) {
            $post = $i === 1 ? $this->working('post', ['title' => 'T1']) : $this->reload('post');
            $post->set('title', 'T' . $i);
            $p->publish($post, null);
        }

        $this->assertSame([5, 4, 3], array_map(static fn ($r) => $r->seq, $p->revisions('post', 'r1')));
    }

    public function test_restore_copies_into_working_copy_only(): void
    {
        $p = $this->publisher();
        $p->publish($this->working('post', ['title' => 'A']), null);
        $post = $this->reload('post');
        $post->set('title', 'B');
        $p->publish($post, null);

        $first = $p->revisions('post', 'r1')[1];
        $p->restore('post', 'r1', $first->id);

        $this->assertSame('A', $this->reload('post')->get('title'));
        $this->assertSame(PublishState::Changed, $p->state($this->reload('post')));
        $this->assertSame('B', $p->snapshot('post', 'r1')?->get('title'));
    }

    /** Storage whose next version-checked write is preceded by another writer's save. */
    private function racing(): RacingDriver
    {
        $driver = new RacingDriver(new JsonFileDriver($this->dir . '/store'));
        $this->dm = new DataManager(
            drivers: ['json' => $driver],
            defaultDriver: 'json',
            typeRegistry: $this->registry,
            validatorFactory: new ValidatorFactory(new RuleFactory()),
        );

        return $driver;
    }

    /** Another request's save of r1, straight to the files, with its own DataManager. */
    private function theirSave(string $title, int $version): \Closure
    {
        return function () use ($title, $version): void {
            $other = new DataManager(['json' => new JsonFileDriver($this->dir . '/store')], 'json', $this->registry);
            $other->saveType($this->working('post', ['title' => $title, '_version' => $version]), false);
        };
    }

    public function test_save_draft_refuses_a_save_landing_between_check_and_write(): void
    {
        $driver = $this->racing();
        $p = $this->publisher();
        $this->dm->saveType($this->working('post', ['title' => 'Start', '_version' => 1]), false);

        $mine = $this->reload('post');
        $mine->set('title', 'Mine');
        $driver->raceOnce($this->theirSave('Theirs', 2));

        try {
            $p->saveDraft($mine, 1);
            $this->fail('expected VersionConflict');
        } catch (VersionConflict $e) {
            $this->assertSame(2, $e->current);
        }
        $this->assertSame('Theirs', $this->reload('post')->get('title'));
        $this->assertSame(2, $this->reload('post')->get('_version'));
    }

    public function test_save_draft_without_a_loaded_version_retries_on_the_newer_version(): void
    {
        $driver = $this->racing();
        $p = $this->publisher();
        $this->dm->saveType($this->working('post', ['title' => 'Start', '_version' => 1]), false);

        $mine = $this->reload('post');
        $mine->set('title', 'Mine');
        $driver->raceOnce($this->theirSave('Theirs', 2));
        $p->saveDraft($mine, null);

        $this->assertSame('Mine', $this->reload('post')->get('title'));
        $this->assertSame(3, $this->reload('post')->get('_version'), 'the version never goes back');
        $this->assertSame(3, $mine->get('_version'));
    }

    public function test_save_draft_of_a_new_record_starts_at_version_one(): void
    {
        $p = $this->publisher();
        $p->saveDraft($this->working('post', ['title' => 'New']), 0);

        $this->assertSame(1, $this->reload('post')->get('_version'));
    }

    public function test_state_with_gives_the_same_state_as_state(): void
    {
        $p = $this->publisher();
        $post = $this->working('post', ['title' => 'A']);
        $p->saveDraft($post, null);
        $cases = ['draft' => $this->reload('post')];

        $p->publish($this->reload('post'), null);
        $cases['published'] = $this->reload('post');

        $scheduled = $this->reload('post');
        $scheduled->set('_publish_at', '2026-11-01T09:00');
        $p->saveDraft($scheduled, null);
        $cases['scheduled, unchanged'] = $this->reload('post');

        $changed = $this->reload('post');
        $changed->set('title', 'B');
        $p->saveDraft($changed, null);
        $cases['changed'] = $this->reload('post');

        $expected = ['draft' => PublishState::Draft, 'published' => PublishState::Published, 'scheduled, unchanged' => PublishState::Published, 'changed' => PublishState::Changed];
        $snapshot = $p->snapshot('post', 'r1');
        foreach ($cases as $case => $working) {
            $this->assertSame($expected[$case], $p->stateWith($working, $case === 'draft' ? null : $snapshot), $case);
        }
        $this->assertSame($p->state($cases['changed']), $p->stateWith($cases['changed'], $snapshot));
    }

    public function test_states_of_some_ids_read_their_snapshots_in_one_query(): void
    {
        $driver = $this->racing();
        $p = $this->publisher();
        foreach (['a' => 'A', 'b' => 'B', 'c' => 'C'] as $id => $title) {
            $record = DynamicRecord::fromArray($this->registry->get('post'), ['uuid' => $id, 'title' => $title]);
            $p->saveDraft($record, null);
            if ($id !== 'c') {
                $p->publish($record, null);
            }
        }
        $b = $this->dm->findType('post', 'b');
        $b->set('title', 'B2');
        $p->saveDraft($b, null);
        $driver->findMany = [];

        $states = $p->states('post', ['a', 'b', 'c', 'missing']);

        $this->assertSame(['a' => PublishState::Published, 'b' => PublishState::Changed, 'c' => PublishState::Draft], $states);
        $this->assertSame(1, $driver->findMany['post__published'] ?? 0);
        $this->assertSame($p->states('post'), $p->states('post', ['a', 'b', 'c']));
        $this->assertSame([], $p->states('post', []));
    }

    /** A long id list is read in chunks (each a query with a bounded IN list), with the same states. */
    public function test_states_of_many_ids_query_in_chunks(): void
    {
        $driver = $this->racing();
        $p = $this->publisher();
        foreach (['a' => 'A', 'b' => 'B'] as $id => $title) {
            $record = DynamicRecord::fromArray($this->registry->get('post'), ['uuid' => $id, 'title' => $title]);
            $p->saveDraft($record, null);
        }
        $p->publish($this->dm->findType('post', 'a'), null);
        $driver->findMany = [];

        $ids = ['a', ...array_map(static fn (int $i): string => "missing-{$i}", range(1, 1100)), 'b'];
        $states = $p->states('post', $ids);

        $this->assertSame(['a' => PublishState::Published, 'b' => PublishState::Draft], $states);
        $this->assertSame(3, $driver->findMany['post__published'] ?? 0);
        $this->assertSame(3, $driver->findMany['post'] ?? 0);
    }

    public function test_publish_with_the_loaded_version_publishes_and_keeps_the_version(): void
    {
        $p = $this->publisher();
        $this->dm->saveType($this->working('post', ['title' => 'Start', '_version' => 3, '_publish_at' => '2030-01-01T00:00:00+00:00']), false);

        $p->publish($this->reload('post'), 'me', 3);

        $this->assertSame('Start', $p->snapshot('post', 'r1')?->get('title'));
        $this->assertSame(3, (int) $this->reload('post')->get('_version'), 'publishing changes no content: open editors stay valid');
        $this->assertSame('', $this->reload('post')->get('_publish_at'));
        $this->assertCount(1, $p->revisions('post', 'r1'));
    }

    public function test_publish_with_a_stale_loaded_version_conflicts_and_writes_nothing(): void
    {
        $p = $this->publisher();
        $this->dm->saveType($this->working('post', ['title' => 'Theirs', '_version' => 2, '_publish_at' => '2030-01-01T00:00:00+00:00']), false);
        $mine = $this->working('post', ['title' => 'Mine', '_version' => 1, '_publish_at' => '2031-01-01T00:00:00+00:00']);

        try {
            $p->publish($mine, 'me', 1);
            $this->fail('expected VersionConflict');
        } catch (VersionConflict $e) {
            $this->assertSame(2, $e->current);
        }
        $this->assertNull($p->snapshot('post', 'r1'));
        $this->assertSame([], $p->revisions('post', 'r1'));
        $this->assertSame('Theirs', $this->reload('post')->get('title'));
        $this->assertSame('2030-01-01T00:00:00+00:00', $this->reload('post')->get('_publish_at'));
        $this->assertSame('2031-01-01T00:00:00+00:00', $mine->get('_publish_at'), 'the record is left as it was');
        $this->assertSame(1, $mine->get('_version'));
    }

    public function test_publish_with_a_loaded_version_refuses_a_save_landing_between_check_and_write(): void
    {
        $driver = $this->racing();
        $p = $this->publisher();
        $this->dm->saveType($this->working('post', ['title' => 'Start', '_version' => 1]), false);
        $mine = $this->reload('post');
        $driver->raceOnce($this->theirSave('Theirs', 2));

        $this->expectException(VersionConflict::class);
        try {
            $p->publish($mine, 'me', 1);
        } finally {
            $this->assertNull($p->snapshot('post', 'r1'));
            $this->assertSame('Theirs', $this->reload('post')->get('title'));
        }
    }

    public function test_publish_with_a_loaded_version_validates_before_it_writes(): void
    {
        $p = $this->publisher();
        $this->dm->saveType($this->working('post', ['title' => '', '_version' => 1, '_publish_at' => '2030-01-01T00:00:00+00:00']), false);

        try {
            $p->publish($this->reload('post'), 'me', 1);
            $this->fail('expected ValidationException');
        } catch (ValidationException) {
        }
        $this->assertSame('2030-01-01T00:00:00+00:00', $this->reload('post')->get('_publish_at'));
    }

    public function test_publish_without_a_loaded_version_stays_unchecked_and_bumps_changed_content(): void
    {
        $p = $this->publisher();
        $this->dm->saveType($this->working('post', ['title' => 'Stored', '_version' => 5]), false);

        $imported = $this->working('post', ['title' => 'Imported']); // no _version, as importers build it
        $p->publish($imported, 'importer');

        $this->assertSame('Imported', $p->snapshot('post', 'r1')?->get('title'));
        $this->assertSame(6, (int) $this->reload('post')->get('_version'), 'never back to none');
        $this->assertSame(6, $imported->get('_version'));
    }

    public function test_publish_without_a_loaded_version_keeps_the_version_of_unchanged_content(): void
    {
        $p = $this->publisher();
        $this->dm->saveType($this->working('post', ['title' => 'Stored', '_version' => 5, '_publish_at' => '2030-01-01T00:00:00+00:00']), false);

        $p->publish($this->reload('post'), 'scheduler');

        $this->assertSame(5, (int) $this->reload('post')->get('_version'), 'clearing the schedule is not content');
    }

    /** A site's approve changes a field and publishes: the version moves on, the record carries it. */
    public function test_publish_with_changed_content_bumps_the_version(): void
    {
        $p = $this->publisher();
        $this->dm->saveType($this->working('post', ['title' => 'Start', 'body' => 'in review', '_version' => 3]), false);

        $approved = $this->reload('post');
        $approved->set('body', 'approved');
        $p->publish($approved, 'reviewer', 3);

        $this->assertSame(4, (int) $this->reload('post')->get('_version'));
        $this->assertSame(4, $approved->get('_version'));
        $this->assertSame('approved', $p->snapshot('post', 'r1')?->get('body'));
    }

    /** An editor left open on the old version must not write the old content back over a publish that changed it. */
    public function test_an_editor_holding_the_version_before_a_changing_publish_conflicts(): void
    {
        $p = $this->publisher();
        $this->dm->saveType($this->working('post', ['title' => 'Start', 'body' => 'in review', '_version' => 3]), false);
        $editor = $this->reload('post'); // open in Folio at version 3

        $approved = $this->reload('post');
        $approved->set('body', 'approved');
        $p->publish($approved, 'reviewer', 3);

        $editor->set('title', 'Autosaved');
        try {
            $p->saveDraft($editor, 3);
            $this->fail('expected VersionConflict');
        } catch (VersionConflict $e) {
            $this->assertSame(4, $e->current);
        }
        $this->assertSame('approved', $this->reload('post')->get('body'));
    }

    public function test_a_changing_publish_against_a_stale_version_conflicts_with_the_stored_version(): void
    {
        $p = $this->publisher();
        $this->dm->saveType($this->working('post', ['title' => 'Theirs', '_version' => 4]), false);
        $mine = $this->working('post', ['title' => 'Mine', '_version' => 3]);

        try {
            $p->publish($mine, 'me', 3);
            $this->fail('expected VersionConflict');
        } catch (VersionConflict $e) {
            $this->assertSame(4, $e->current);
        }
        $this->assertSame(3, $mine->get('_version'), 'the record is left as it was');
        $this->assertNull($p->snapshot('post', 'r1'));
    }

    public function test_discard_and_restore_with_a_stale_loaded_version_conflict(): void
    {
        $p = $this->publisher();
        $p->saveDraft($this->working('post', ['title' => 'Published']), 0);
        $p->publish($this->reload('post'), 'me', 1);
        $edited = $this->reload('post');
        $edited->set('title', 'Edited');
        $p->saveDraft($edited, 1); // version 2
        $revision = $p->revisions('post', 'r1')[0]->id;

        foreach (['discard' => fn () => $p->discard('post', 'r1', 1), 'restore' => fn () => $p->restore('post', 'r1', $revision, 1)] as $what => $call) {
            try {
                $call();
                $this->fail("{$what}: expected VersionConflict");
            } catch (VersionConflict $e) {
                $this->assertSame(2, $e->current, $what);
            }
            $this->assertSame('Edited', $this->reload('post')->get('title'), $what);
        }

        $this->assertSame('Published', $p->discard('post', 'r1', 2)?->get('title'));
        $this->assertSame(3, (int) $this->reload('post')->get('_version'));
        $this->assertSame('Published', $p->restore('post', 'r1', $revision, 3)?->get('title'));
        $this->assertSame(4, (int) $this->reload('post')->get('_version'));
    }

    private function publisher(int $keep = 20): Publisher
    {
        $fieldTypes = new FieldTypeRegistry();
        $fieldTypes->register(new StringFieldType());
        $fieldTypes->register(new TextFieldType());

        return new Publisher(
            $this->dm,
            new TypeCatalog($this->dir . '/models'),
            new RecordValidator($fieldTypes, $this->dm),
            $keep,
            fn (): \DateTimeImmutable => $this->now,
        );
    }

    /** @param array<string, mixed> $data */
    private function working(string $type, array $data): DynamicRecord
    {
        return DynamicRecord::fromArray($this->registry->get($type), ['uuid' => 'r1'] + $data);
    }

    private function reload(string $type): DynamicRecord
    {
        return $this->dm->findType($type, 'r1') ?? throw new \RuntimeException('missing');
    }

    /** @param array<string, mixed> $config */
    private function model(string $key, array $config): void
    {
        file_put_contents($this->dir . "/models/{$key}.json", json_encode(
            ['key' => $key, 'table' => $key, 'storage' => 'json', 'id_field' => 'uuid'] + $config,
        ));
    }
}
