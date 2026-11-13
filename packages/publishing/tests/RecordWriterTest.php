<?php

declare(strict_types=1);

namespace Preflow\Publishing\Tests;

use Preflow\Data\DynamicRecord;
use Preflow\Publishing\Publisher;
use Preflow\Publishing\RecordWriter;
use Preflow\Publishing\SaveOptions;
use Preflow\Publishing\SchemaPublishingTypes;
use Preflow\Publishing\Tests\Fixtures\Recorder;
use Preflow\Publishing\Tests\Fixtures\ThrowingAfter;
use Preflow\Publishing\VersionConflict;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidationResult;

/**
 * The one write path, without Folio: "post" publishes, "note" does not. Every interface the
 * writer asks is the Recorder, so the log shows the order of the steps.
 */
final class RecordWriterTest extends PublishingAppTestCase
{
    private Recorder $recorder;

    private function writer(string $now = '2030-01-01 12:00'): RecordWriter
    {
        $this->recorder ??= new Recorder($this->dm());
        $publisher = new Publisher($this->dm(), new SchemaPublishingTypes($this->dm()), $this->recorder, index: $this->recorder, addresses: $this->recorder);

        return new RecordWriter($this->dm(), $publisher, $this->recorder, $this->recorder, $this->recorder, $this->recorder, $this->recorder, static fn (): \DateTimeImmutable => new \DateTimeImmutable($now));
    }

    /** @param array<string, mixed> $data */
    private function record(string $type, string $id, array $data = []): DynamicRecord
    {
        return DynamicRecord::fromArray($this->dm()->getTypeRegistry()->get($type), ['uuid' => $id] + $data);
    }

    private static function refusal(): ValidationException
    {
        return new ValidationException(new ValidationResult(['title' => ['Not like this.']]));
    }

    public function test_a_publishing_type_saves_its_draft_then_publishes_then_runs_after(): void
    {
        $writer = $this->writer();

        $outcome = $writer->save($this->record('post', 'p1', ['title' => ['en' => 'Hello']]), new SaveOptions(by: 'ana', publish: true));

        $this->assertSame([
            'before:post:p1',
            'refresh:post:p1',      // the draft (Publisher::saveDraft)
            'checks:post:p1',       // the publish
            'changed:->post:p1',
            'sync:post:p1',
            'refresh:post:p1',
            'after:post:p1',
        ], $this->recorder->log);
        $this->assertFalse($outcome->addressesLeft);
        $this->assertSame('ana', $this->publisher()->snapshot('post', 'p1')?->get('_published_by'));
        $this->assertSame(['en' => 'Hello'], $this->publisher()->live('post', 'p1')?->get('title'));
    }

    public function test_a_publishing_type_without_publish_saves_the_draft_only(): void
    {
        $this->writer()->save($this->record('post', 'p1', ['title' => ['en' => 'Hello']]));

        $this->assertSame(['before:post:p1', 'refresh:post:p1', 'after:post:p1'], $this->recorder->log);
        $this->assertSame(1, $this->stored('post', 'p1')->get('_version'));
        $this->assertNull($this->publisher()->snapshot('post', 'p1'));
    }

    public function test_another_type_is_checked_stored_indexed_and_synced(): void
    {
        $writer = $this->writer();
        $this->recorder->left = true;

        $outcome = $writer->save($this->record('note', 'n1', ['title' => ['en' => 'Note']]));

        $this->assertSame(['before:note:n1', 'checks:note:n1', 'refresh:note:n1', 'sync:note:n1', 'after:note:n1'], $this->recorder->log);
        $this->assertTrue($outcome->addressesLeft, 'an address left the site');
        $this->assertSame(['en' => 'Note'], $this->stored('note', 'n1')->get('title'));
    }

    public function test_a_type_that_saves_itself_is_handed_the_record_and_the_previous_one(): void
    {
        $writer = $this->writer();
        $this->recorder->replaces = ['note'];

        $writer->save($this->record('note', 'n1', ['title' => ['en' => 'One']]));
        $writer->save($this->record('note', 'n1', ['title' => ['en' => 'Two']]));

        $this->assertSame(['before:note:n1', 'checks:note:n1', 'save:note:n1', 'refresh:note:n1', 'sync:note:n1', 'after:note:n1'], array_slice($this->recorder->log, 0, 6));
        $this->assertSame([['n1', null], ['n1', 'n1']], $this->recorder->saved);
    }

    public function test_a_refusal_before_the_save_stores_nothing_on_every_type(): void
    {
        $writer = $this->writer();
        $this->recorder->failBefore = static fn () => throw self::refusal();

        foreach (['post' => new SaveOptions(publish: true), 'note' => new SaveOptions()] as $type => $options) {
            try {
                $writer->save($this->record($type, 'x1', ['title' => ['en' => 'X']]), $options);
                $this->fail('Expected ValidationException');
            } catch (ValidationException $e) {
                $this->assertSame(['title' => ['Not like this.']], $e->errors());
            }
            $this->assertNull($this->dm()->findType($type, 'x1'), $type);
        }
        $this->assertSame(['before:post:x1', 'before:note:x1'], $this->recorder->log);
    }

    public function test_failed_checks_of_another_type_store_nothing_and_skip_after(): void
    {
        $writer = $this->writer();
        $this->recorder->failChecks = static fn () => throw self::refusal();

        try {
            $writer->save($this->record('note', 'n1'));
            $this->fail('Expected ValidationException');
        } catch (ValidationException) {
        }

        $this->assertNull($this->dm()->findType('note', 'n1'));
        $this->assertSame(['before:note:n1', 'checks:note:n1'], $this->recorder->log);
    }

    public function test_after_runs_when_the_publish_fails_and_the_failure_is_rethrown(): void
    {
        $writer = $this->writer();
        $this->recorder->failChecks = static fn () => throw self::refusal();

        try {
            $writer->save($this->record('post', 'p1', ['title' => ['en' => 'Draft']]), new SaveOptions(publish: true));
            $this->fail('Expected ValidationException');
        } catch (ValidationException) {
        }

        $this->assertSame(['before:post:p1', 'refresh:post:p1', 'checks:post:p1', 'after:post:p1'], $this->recorder->log);
        $this->assertSame(['en' => 'Draft'], $this->stored('post', 'p1')->get('title'), 'the draft is saved');
        $this->assertNull($this->publisher()->snapshot('post', 'p1'));
    }

    public function test_after_runs_when_the_publish_conflicts(): void
    {
        $writer = $this->writer();
        // A save landing between the draft and the publish: the publish's version check fails.
        $this->recorder->failChecks = function (DynamicRecord $record): void {
            $other = $this->stored('post', (string) $record->getId());
            $other->set('_version', (int) $other->get('_version') + 1);
            $this->dm()->saveType($other, false);
        };

        try {
            $writer->save($this->record('post', 'p1', ['title' => ['en' => 'Draft']]), new SaveOptions(publish: true));
            $this->fail('Expected VersionConflict');
        } catch (VersionConflict $e) {
            $this->assertSame(2, $e->current);
        }

        $this->assertSame('after:post:p1', array_last($this->recorder->log));
        $this->assertNull($this->publisher()->snapshot('post', 'p1'));
    }

    public function test_a_failing_after_hook_does_not_hide_the_failed_publish(): void
    {
        $recorder = new Recorder($this->dm());
        $recorder->failChecks = static fn () => throw self::refusal();
        $writer = new RecordWriter($this->dm(), new Publisher($this->dm(), new SchemaPublishingTypes($this->dm()), $recorder), $recorder, new ThrowingAfter($recorder));

        $this->expectException(ValidationException::class);
        $writer->save($this->record('post', 'p1'), new SaveOptions(publish: true));
    }

    public function test_a_stale_version_conflicts_before_after(): void
    {
        $writer = $this->writer();
        $writer->save($this->record('post', 'p1', ['title' => ['en' => 'One']]));
        $writer->save($this->stored('post', 'p1'), new SaveOptions(loadedVersion: 1));
        $this->recorder->log = [];

        $this->expectException(VersionConflict::class);
        try {
            $writer->save($this->stored('post', 'p1'), new SaveOptions(loadedVersion: 1));
        } finally {
            $this->assertSame(['before:post:p1'], $this->recorder->log);
        }
    }

    /** Review Focus 1: a save by someone who may not publish cancels a pending schedule. */
    public function test_a_save_that_may_not_publish_cancels_the_schedule(): void
    {
        $writer = $this->writer();
        $writer->save($this->record('post', 'p1', ['title' => ['en' => 'One']]), new SaveOptions(publishAt: '2030-02-01T09:00'));
        $this->assertSame('2030-02-01T09:00', $this->stored('post', 'p1')->get('_publish_at'));

        $writer->save($this->stored('post', 'p1'), new SaveOptions(mayPublish: false, publishAt: '2030-03-01T09:00'));
        $this->assertSame('', $this->stored('post', 'p1')->get('_publish_at'), 'cancelled, and no new schedule');

        $writer->save($this->stored('post', 'p1'), new SaveOptions(publishAt: '2030-02-01T09:00'));
        $writer->setField($this->stored('post', 'p1'), 'title', ['en' => 'Two'], new SaveOptions(mayPublish: false));
        $this->assertSame('', $this->stored('post', 'p1')->get('_publish_at'), 'a field set by someone who may not publish too');
        $this->assertSame(['en' => 'Two'], $this->stored('post', 'p1')->get('title'));
    }

    public function test_a_schedule_a_before_hook_sets_is_cleared_too_when_the_save_may_not_publish(): void
    {
        $writer = $this->writer();
        $this->recorder->failBefore = static fn (DynamicRecord $record) => $record->set('_publish_at', '2030-02-01T09:00');

        $writer->save($this->record('post', 'p1'), new SaveOptions(mayPublish: false));

        $this->assertSame('', $this->stored('post', 'p1')->get('_publish_at'));
    }

    public function test_the_schedule_is_kept_set_cleared_and_never_in_the_past(): void
    {
        $writer = $this->writer('2030-01-01 12:00');
        $writer->save($this->record('post', 'p1'), new SaveOptions(publishAt: '2030-02-01T09:00'));

        $writer->save($this->stored('post', 'p1'));
        $this->assertSame('2030-02-01T09:00', $this->stored('post', 'p1')->get('_publish_at'), 'null keeps it');

        $writer->save($this->stored('post', 'p1'), new SaveOptions(publishAt: '2029-12-31T09:00'));
        $this->assertSame('', $this->stored('post', 'p1')->get('_publish_at'), 'a past time is dropped');

        $writer->save($this->stored('post', 'p1'), new SaveOptions(publishAt: '2030-02-01T09:00'));
        $writer->save($this->stored('post', 'p1'), new SaveOptions(publishAt: 'tomorrow'));
        $this->assertSame('', $this->stored('post', 'p1')->get('_publish_at'), 'a malformed time clears it');

        $writer->save($this->stored('post', 'p1'), new SaveOptions(publishAt: '2030-02-01T09:00'));
        $writer->save($this->stored('post', 'p1'), new SaveOptions(publishAt: ''));
        $this->assertSame('', $this->stored('post', 'p1')->get('_publish_at'), "'' clears it");
    }

    /** Review Focus 1 for a restore: one write, the schedule cancelled with the restored content. */
    public function test_a_restore_by_someone_who_may_not_publish_cancels_the_schedule(): void
    {
        $writer = $this->writer();
        $writer->save($this->record('post', 'p1', ['title' => ['en' => 'One']]), new SaveOptions(publish: true));
        $second = $this->stored('post', 'p1');
        $second->set('title', ['en' => 'Two']);
        $writer->save($second, new SaveOptions(publish: true));
        $writer->save($this->stored('post', 'p1'), new SaveOptions(publishAt: '2030-02-01T09:00'));
        $first = $this->publisher()->revisions('post', 'p1')[1];
        $this->recorder->log = [];

        $restored = $writer->restore('post', 'p1', $first->id);
        $this->assertSame(['en' => 'One'], $restored?->get('title'));
        $this->assertSame('2030-02-01T09:00', $this->stored('post', 'p1')->get('_publish_at'), 'kept for someone who may publish');

        $version = (int) $this->stored('post', 'p1')->get('_version');
        $writer->restore('post', 'p1', $first->id, new SaveOptions(mayPublish: false));
        $this->assertSame('', $this->stored('post', 'p1')->get('_publish_at'));
        $this->assertSame($version + 1, (int) $this->stored('post', 'p1')->get('_version'), 'one write');
        $this->assertNotContains('before:post:p1', $this->recorder->log, 'a restore is not an edit');
        $this->assertNull($writer->restore('post', 'p1', 'no-such-revision'));
    }

    public function test_a_restore_neither_publishes_nor_restores_a_type_without_publishing(): void
    {
        try {
            $this->writer()->restore('post', 'p1', 'r', new SaveOptions(publish: true));
            $this->fail('A restore that publishes is refused.');
        } catch (\InvalidArgumentException) {
        }
        $this->expectException(\InvalidArgumentException::class);

        $this->writer()->restore('note', 'n1', 'r');
    }

    public function test_without_a_publisher_no_type_publishes(): void
    {
        $this->recorder = new Recorder($this->dm());
        $writer = new RecordWriter($this->dm(), null, $this->recorder, $this->recorder);

        $writer->save($this->record('post', 'p1', ['title' => ['en' => 'Live']]), new SaveOptions(mayPublish: false));

        $this->assertSame(['before:post:p1', 'checks:post:p1', 'after:post:p1'], $this->recorder->log);
        $this->assertSame(['en' => 'Live'], $this->stored('post', 'p1')->get('title'));
        $this->assertNull($this->stored('post', 'p1')->get('_publish_at'), 'no schedule rule for a type that does not publish');
    }

    public function test_publishing_needs_the_right_to(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SaveOptions(mayPublish: false, publish: true);
    }

    public function test_create_assigns_an_id_and_puts_an_orderable_record_last(): void
    {
        $writer = $this->writer();
        $this->recorder->orderable = ['note'];
        $this->working('note', 'n1', ['_order' => 10]);
        $this->working('note', 'n2', ['_order' => '30']);

        $outcome = $writer->create($this->record('note', '', ['title' => ['en' => 'New']]));
        $id = (string) $outcome->record->getId();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
        $this->assertSame(40, $this->stored('note', $id)->get('_order'));
        $this->assertSame(['en' => 'New'], $this->stored('note', $id)->get('title'));

        $kept = $writer->create($this->record('post', 'mine'));
        $this->assertSame('mine', $kept->record->getId(), 'an id given is kept');
        $this->assertNull($this->stored('post', 'mine')->get('_order'), 'post is not orderable');
    }

    public function test_set_field_names_a_field_of_the_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->writer()->setField($this->record('note', 'n1'), 'nope', 'x');
    }

    public function test_reorder_swaps_neighbours_through_the_hooks_and_stops_at_the_edges(): void
    {
        $writer = $this->writer();
        $this->recorder->orderable = ['note'];
        $this->working('note', 'a', ['_order' => 10]);
        $this->working('note', 'b', ['_order' => 20]);
        $this->working('note', 'c', ['_order' => 30]);

        $this->assertFalse($writer->reorder('note', 'a', 'up'), 'first');
        $this->assertFalse($writer->reorder('note', 'c', 'down'), 'last');
        $this->assertSame([], $this->recorder->log, 'nothing saved at the edges');

        $this->assertTrue($writer->reorder('note', 'c', 'up'));
        $this->assertSame(['before:note:c', 'before:note:b', 'after:note:c', 'after:note:b'], $this->recorder->log, 'no content checks');
        $this->assertSame([10, 30, 20], array_map(fn (string $id): int => (int) $this->stored('note', $id)->get('_order'), ['a', 'b', 'c']));
    }

    public function test_reorder_numbers_records_without_a_position_first(): void
    {
        $writer = $this->writer();
        $this->recorder->orderable = ['note'];
        $this->working('note', 'a', []);
        $this->working('note', 'b', []);
        $this->working('note', 'c', []);

        $this->assertTrue($writer->reorder('note', 'a', 'down'));

        $this->assertSame([20, 10, 30], array_map(fn (string $id): int => (int) $this->stored('note', $id)->get('_order'), ['a', 'b', 'c']));
    }

    public function test_reorder_uses_the_types_own_save_and_carries_positions_into_snapshots(): void
    {
        $writer = $this->writer();
        $this->recorder->orderable = ['note', 'post'];
        $this->recorder->replaces = ['note'];
        $this->working('note', 'a', ['_order' => 10]);
        $this->working('note', 'b', ['_order' => 20]);
        $writer->reorder('note', 'b', 'up');
        $this->assertSame([['b', 'b'], ['a', 'a']], $this->recorder->saved);

        foreach (['p1' => 10, 'p2' => 20] as $id => $order) {
            $this->publisher()->publish($this->working('post', $id, ['title' => ['en' => $id], '_order' => $order]), null);
        }
        $versions = [$this->stored('post', 'p1')->get('_version'), $this->stored('post', 'p2')->get('_version')];
        $writer->reorder('post', 'p2', 'up');

        $this->assertSame(20, (int) $this->publisher()->snapshot('post', 'p1')?->get('_order'));
        $this->assertSame(10, (int) $this->publisher()->snapshot('post', 'p2')?->get('_order'));
        $this->assertSame($versions, [$this->stored('post', 'p1')->get('_version'), $this->stored('post', 'p2')->get('_version')], 'a position is no new version');
    }

    /** Review Focus 2 for reorder: a refusal before the save stores no position. */
    public function test_a_refusal_before_a_reorder_stores_nothing(): void
    {
        $writer = $this->writer();
        $this->recorder->orderable = ['note'];
        $this->working('note', 'a', []);
        $this->working('note', 'b', []);
        $this->recorder->failBefore = static function (DynamicRecord $record): void {
            if ($record->getId() === 'b') {
                throw self::refusal();
            }
        };

        try {
            $writer->reorder('note', 'a', 'down');
            $this->fail('Expected ValidationException');
        } catch (ValidationException) {
        }

        $this->assertNull($this->stored('note', 'a')->get('_order'));
        $this->assertNull($this->stored('note', 'b')->get('_order'));
    }

    public function test_reorder_refuses_an_unknown_record(): void
    {
        $writer = $this->writer();
        $this->recorder->orderable = ['note'];
        $this->working('note', 'a', ['_order' => 10]);

        $this->expectException(\InvalidArgumentException::class);
        $writer->reorder('note', 'zz', 'up');
    }

    public function test_create_refuses_an_id_that_exists(): void
    {
        $writer = $this->writer();
        $this->working('note', 'n1', ['title' => ['en' => 'Kept']]);

        try {
            $writer->create($this->record('note', 'n1', ['title' => ['en' => 'Other']]));
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException) {
        }

        $this->assertSame([], $this->recorder->log, 'refused before any hook');
        $this->assertSame(['en' => 'Kept'], $this->stored('note', 'n1')->get('title'));
    }

    public function test_reorder_refuses_a_type_that_is_not_orderable(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->writer()->reorder('note', 'a', 'up');
    }

    public function test_the_container_binds_the_writer_with_the_defaults(): void
    {
        $writer = $this->app()->container()->get(RecordWriter::class);

        $saved = $writer->create($this->record('note', '', ['title' => ['en' => 'Plain']]))->record;

        $this->assertSame(['en' => 'Plain'], $this->stored('note', (string) $saved->getId())->get('title'));
        $this->assertNull($this->stored('note', (string) $saved->getId())->get('_order'), 'no type is orderable by default');
    }
}
