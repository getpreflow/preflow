<?php

declare(strict_types=1);

namespace Preflow\Publishing\Tests;

use Preflow\Core\Activity\Activity;
use Preflow\Core\Activity\ActivityKind;
use Preflow\Core\Activity\ActivityLog;
use Preflow\Core\Activity\ActivityPage;
use Preflow\Data\Activity\StoredActivityLog;
use Preflow\Data\DynamicRecord;
use Preflow\Publishing\RecordDeleter;
use Preflow\Publishing\RecordWriter;
use Preflow\Publishing\SaveOptions;
use Preflow\Publishing\Scheduler;
use Preflow\Publishing\WriteKind;

/**
 * The writers tell the activity log (the app's StoredActivityLog on JSON): "post" publishes,
 * "note" does not.
 */
final class ActivityWritersTest extends PublishingAppTestCase
{
    private function writer(): RecordWriter
    {
        return $this->app()->container()->get(RecordWriter::class);
    }

    private function log(): ActivityLog
    {
        return $this->app()->container()->get(ActivityLog::class);
    }

    /** @param array<string, mixed> $data */
    private function record(string $type, string $id, array $data = []): DynamicRecord
    {
        return DynamicRecord::fromArray($this->dm()->getTypeRegistry()->get($type), ['uuid' => $id] + $data);
    }

    /** @return list<string> "kind subject:id by [changed]" newest first */
    private function rows(): array
    {
        return array_map(
            static fn (Activity $a): string => trim("{$a->kind->value} {$a->subject}:{$a->id} " . ($a->by ?? '-') . ' ' . implode(',', $a->changed)),
            $this->log()->recent(50)->items,
        );
    }

    public function test_the_app_keeps_a_stored_log(): void
    {
        $this->assertInstanceOf(StoredActivityLog::class, $this->log());
    }

    public function test_create_save_and_publish(): void
    {
        $writer = $this->writer();
        $post = $this->record('post', '', ['title' => ['en' => 'Hello', 'de' => 'Hallo']]);
        $writer->create($post, new SaveOptions(by: 'ana'));
        $id = (string) $post->getId();

        $post->set('body', ['en' => 'Text']);
        $writer->save($post, new SaveOptions(by: 'ben'));
        $writer->save($post, new SaveOptions(by: 'ben', publish: true));

        $this->assertSame([
            "published post:{$id} ben title,body",
            "saved post:{$id} ben body",
            "created post:{$id} ana title",
        ], $this->rows());
        $this->assertSame('Hello', $this->log()->recent(1)->items[0]->title, 'the title in the default language');
    }

    public function test_a_save_without_changes_says_nothing_changed(): void
    {
        $writer = $this->writer();
        $note = $this->record('note', 'n1', ['title' => ['en' => 'Note']]);
        $writer->create($note, new SaveOptions(by: 'ana'));
        $writer->save($this->stored('note', 'n1'), new SaveOptions(by: 'ben'));

        $this->assertSame(['saved note:n1 ben', 'created note:n1 ana title'], $this->rows());
    }

    public function test_a_save_of_a_new_record_is_its_creation(): void
    {
        $this->writer()->save($this->record('note', 'n1', ['title' => ['en' => 'Note']]), new SaveOptions(by: 'ana'));

        $this->assertSame(['created note:n1 ana title'], $this->rows());
    }

    public function test_create_and_publish_at_once(): void
    {
        $this->writer()->create($this->record('post', 'p1', ['title' => ['en' => 'Hello']]), new SaveOptions(by: 'ana', publish: true));

        $this->assertSame(['published post:p1 ana title', 'created post:p1 ana title'], $this->rows());
    }

    public function test_a_copy_is_created(): void
    {
        $writer = $this->writer();
        $writer->create($this->record('note', 'n1', ['title' => ['en' => 'Note']]), new SaveOptions(by: 'ana'));
        $copy = $writer->duplicate('note', 'n1', new SaveOptions(by: 'ben'))->record;

        $this->assertSame('created note:' . $copy->getId() . ' ben title', $this->rows()[0]);
    }

    public function test_autosaves_coalesce(): void
    {
        $writer = $this->writer();
        $writer->create($this->record('post', 'p1', ['title' => ['en' => 'Hello']]), new SaveOptions(by: 'ana'));
        foreach (['title' => ['en' => 'Hello!'], 'body' => ['en' => 'One'], 'body ' => ['en' => 'Two']] as $field => $value) {
            $post = $this->stored('post', 'p1');
            $post->set(trim($field), $value);
            $writer->save($post, new SaveOptions(by: 'ana', kind: WriteKind::Autosave));
        }

        $this->assertSame(['saved post:p1 ana title,body', 'created post:p1 ana title'], $this->rows());
    }

    public function test_schedule_unpublish_and_restore(): void
    {
        $writer = $this->writer();
        $writer->create($this->record('post', 'p1', ['title' => ['en' => 'One']]), new SaveOptions(by: 'ana', publish: true));
        $post = $this->stored('post', 'p1');
        $post->set('title', ['en' => 'Two']);
        $writer->save($post, new SaveOptions(by: 'ana', publishAt: '2099-01-01T09:00'));
        $this->publisher()->unpublish('post', 'p1', null, 'ben');
        $revision = $this->publisher()->revisions('post', 'p1')[0]->id;
        $writer->restore('post', 'p1', $revision, new SaveOptions(by: 'cleo'));
        $this->publisher()->publish($this->stored('post', 'p1'), 'cleo');
        $post = $this->stored('post', 'p1');
        $post->set('title', ['en' => 'Three']);
        $writer->save($post, new SaveOptions(by: 'dan'));
        $writer->discard('post', 'p1', new SaveOptions(by: 'dan'));

        $this->assertSame([
            'discarded post:p1 dan',
            'saved post:p1 dan title',
            'published post:p1 cleo title',
            'restored post:p1 cleo',
            'unpublished post:p1 ben',
            'scheduled post:p1 ana title',
            'published post:p1 ana title',
            'created post:p1 ana title',
        ], $this->rows());
    }

    public function test_the_scheduler_publishes_as_itself(): void
    {
        $writer = $this->writer();
        $writer->create($this->record('post', 'p1', ['title' => ['en' => 'Later'], '_publish_at' => '2000-01-01T09:00']), new SaveOptions(by: 'ana'));
        $this->assertSame(['post/p1'], $this->app()->container()->get(Scheduler::class)->runDue(new \DateTimeImmutable())['published']);

        $this->assertSame('published post:p1 scheduler title', $this->rows()[0]);
    }

    public function test_a_deleted_record_keeps_its_rows(): void
    {
        $writer = $this->writer();
        $writer->create($this->record('post', 'p1', ['title' => ['en' => 'Gone soon']]), new SaveOptions(by: 'ana', publish: true));
        $this->app()->container()->get(RecordDeleter::class)->delete('post', ['p1'], null, 'ben');

        $this->assertSame(['deleted post:p1 ben', 'published post:p1 ana title', 'created post:p1 ana title'], $this->rows());
        $this->assertSame(['Gone soon', 'Gone soon', 'Gone soon'], array_map(static fn (Activity $a): string => $a->title, $this->log()->recent(5)->items));
        $this->assertNull($this->dm()->findType('post', 'p1'));
    }

    public function test_failing_log_does_not_fail_the_save(): void
    {
        $failing = new class implements ActivityLog {
            public int $calls = 0;

            public function record(Activity $activity): void
            {
                $this->calls++;
                throw new \RuntimeException('the log is down');
            }

            public function recent(int $limit, ?array $subjects = null, ?string $before = null, ?string $by = null): ActivityPage
            {
                return new ActivityPage();
            }

            public function people(?array $subjects = null): array
            {
                return [];
            }

            public function prune(): int
            {
                return 0;
            }

            public function forget(string $subject, string $id): void
            {
            }
        };
        $logger = new class extends \Psr\Log\AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->lines[] = $level . ': ' . $message;
            }
        };
        $container = $this->app()->container();
        $container->singleton(ActivityLog::class, static fn (): ActivityLog => $failing);
        $container->instance(\Psr\Log\LoggerInterface::class, $logger);

        $writer = $this->writer();
        $writer->create($this->record('post', 'p1', ['title' => ['en' => 'Safe']]), new SaveOptions(by: 'ana', publish: true));
        $post = $this->stored('post', 'p1');
        $post->set('body', ['en' => 'More']);
        $writer->save($post, new SaveOptions(by: 'ana', kind: WriteKind::Autosave));
        $this->publisher()->unpublish('post', 'p1', null, 'ana');
        $writer->create($this->record('note', 'n1', ['title' => ['en' => 'Plain']]), new SaveOptions(by: 'ana'));
        $container->get(RecordDeleter::class)->delete('note', ['n1'], null, 'ana');

        $this->assertSame(['en' => 'More'], $this->stored('post', 'p1')->get('body'));
        $this->assertNull($this->publisher()->snapshot('post', 'p1'));
        $this->assertNull($this->dm()->findType('note', 'n1'));
        $this->assertSame(6, $failing->calls);
        $this->assertCount(6, $logger->lines);
        $this->assertStringContainsString('warning: Activity log: "created" of post p1 not recorded: the log is down', $logger->lines[0]);
    }
}
