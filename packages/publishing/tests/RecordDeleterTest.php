<?php

declare(strict_types=1);

namespace Preflow\Publishing\Tests;

use Preflow\Publishing\DeleteRefused;
use Preflow\Publishing\Publisher;
use Preflow\Publishing\RecordDeleter;
use Preflow\Publishing\RemovalTarget;
use Preflow\Publishing\SchemaPublishingTypes;
use Preflow\Publishing\Tests\Fixtures\Recorder;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidationResult;

/**
 * The one delete path, without Folio: author <- book (cascade; books publish),
 * book <- shelf.books (detach, multiple), book <- loan (restrict).
 */
final class RecordDeleterTest extends PublishingAppTestCase
{
    private Recorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();
        $models = [
            'author' => ['fields' => ['name' => ['type' => 'string']]],
            'book' => ['publishing' => true, 'fields' => [
                'title' => ['type' => 'string'],
                'author' => ['type' => 'relation', 'relation' => ['to' => 'author', 'onDelete' => 'cascade']],
            ]],
            'shelf' => ['fields' => ['books' => ['type' => 'relation', 'relation' => ['to' => 'book', 'multiple' => true]]]],
            'loan' => ['fields' => ['book' => ['type' => 'relation', 'relation' => ['to' => 'book', 'onDelete' => 'restrict']]]],
        ];
        foreach ($models as $key => $model) {
            $this->write("config/models/{$key}.json", (string) json_encode(['key' => $key, 'table' => $key, 'storage' => 'json'] + $model));
        }
    }

    private function deleter(): RecordDeleter
    {
        $this->recorder ??= new Recorder($this->dm());
        $types = new SchemaPublishingTypes($this->dm());
        $publisher = new Publisher($this->dm(), $types, $this->recorder, index: $this->recorder, addresses: $this->recorder);

        return new RecordDeleter($this->dm(), $types, $publisher, $this->recorder, $this->recorder, $this->recorder);
    }

    private function seedWorld(): void
    {
        $this->working('author', 'a1', ['name' => 'Ursula']);
        $this->working('author', 'a2', ['name' => 'Terry']);
        $this->publisher()->publish($this->working('book', 'b1', ['title' => 'Earthsea', 'author' => 'a1']), null);
        $this->working('book', 'b2', ['title' => 'Mort', 'author' => 'a2']);
        $this->working('shelf', 's1', ['books' => ['b1', 'b2']]);
    }

    public function test_delete_cascades_detaches_and_runs_every_step_in_order(): void
    {
        $this->seedWorld();
        $deleter = $this->deleter();

        $deleter->delete('author', ['a1']);

        $this->assertSame([
            'finalTarget:author=a1,book=b1',
            'before:shelf:s1',
            'beforeDelete:author:a1',
            'beforeDelete:book:b1',
            'changed:book:b1>-',   // Publisher::forget(): the snapshot leaves its addresses
            'forget:author:a1',
            'removed:author:a1',
            'forget:book:b1',
            'removed:book:b1',
            'after:shelf:s1',
            'afterDelete:author:a1',
            'afterDelete:book:b1',
        ], $this->recorder->log);
        $this->assertNull($this->dm()->findType('author', 'a1'));
        $this->assertNull($this->dm()->findType('book', 'b1'));
        $this->assertNull($this->publisher()->snapshot('book', 'b1'), 'the snapshot is forgotten');
        $this->assertSame([], $this->publisher()->revisions('book', 'b1'), 'and its revisions');
        $this->assertSame('["b2"]', $this->stored('shelf', 's1')->get('books'), 'detached (a relation list is stored as JSON)');
        $this->assertNotNull($this->dm()->findType('author', 'a2'));
    }

    public function test_the_target_is_resolved_before_the_delete_and_reaches_every_removed_record(): void
    {
        $this->seedWorld();
        $deleter = $this->deleter();
        $asked = new class implements RemovalTarget {};
        $final = new class implements RemovalTarget {};
        $this->recorder->final = $final;

        $deleter->delete('author', ['a1'], $asked);

        $this->assertSame([$final, $final], $this->recorder->targets);
    }

    public function test_a_restricted_plan_is_refused_before_any_hook(): void
    {
        $this->seedWorld();
        $this->working('loan', 'l1', ['book' => 'b1']);
        $deleter = $this->deleter();

        try {
            $deleter->delete('author', ['a1']);
            $this->fail('Expected DeleteRefused');
        } catch (DeleteRefused $e) {
            $this->assertTrue($e->plan->restricted());
            $this->assertSame(['loan' => 1], $e->plan->restrictions());
            $this->assertNull($e->record);
        }

        $this->assertSame([], $this->recorder->log);
        $this->assertNotNull($this->dm()->findType('author', 'a1'));
    }

    public function test_a_refused_detach_cancels_everything(): void
    {
        $this->seedWorld();
        $deleter = $this->deleter();
        $this->recorder->failBefore = static fn () => throw new ValidationException(new ValidationResult(['books' => ['A shelf needs a book.']]));

        try {
            $deleter->delete('author', ['a1']);
            $this->fail('Expected DeleteRefused');
        } catch (DeleteRefused $e) {
            $this->assertSame('s1', $e->record?->getId());
            $this->assertSame(['books' => ['A shelf needs a book.']], $e->validation?->errors());
        }

        $this->assertNotNull($this->dm()->findType('author', 'a1'));
        $this->assertNotNull($this->publisher()->snapshot('book', 'b1'));
        $this->assertSame(['b1', 'b2'], $this->stored('shelf', 's1')->get('books'));
    }

    public function test_a_hook_refusing_a_cascaded_delete_leaves_everything_in_place(): void
    {
        $this->seedWorld();
        $deleter = $this->deleter();
        $this->recorder->failBeforeDelete = static function ($record): void {
            if ($record->getId() === 'b1') {
                throw new \RuntimeException('Earthsea stays.');
            }
        };

        try {
            $deleter->delete('author', ['a1']);
            $this->fail('Expected the hook\'s exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('Earthsea stays.', $e->getMessage());
        }

        $this->assertNotNull($this->dm()->findType('author', 'a1'));
        $this->assertNotNull($this->dm()->findType('book', 'b1'));
        $this->assertSame(['b1', 'b2'], $this->stored('shelf', 's1')->get('books'));
        $this->assertNotContains('afterDelete:author:a1', $this->recorder->log);
    }

    public function test_unknown_ids_are_never_planned(): void
    {
        $this->seedWorld();

        $plan = $this->deleter()->plan('author', ['nope', 'a1', 'a1', 'b1']);

        $this->assertSame(['author' => ['a1'], 'book' => ['b1']], $plan->deletes());
    }

    public function test_the_container_binds_the_deleter(): void
    {
        $this->seedWorld();

        $this->app()->container()->get(RecordDeleter::class)->delete('author', ['a2']);

        $this->assertNull($this->dm()->findType('author', 'a2'));
        $this->assertNull($this->dm()->findType('book', 'b2'));
        $this->assertSame('["b1"]', $this->stored('shelf', 's1')->get('books'));
    }
}
