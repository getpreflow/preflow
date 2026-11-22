<?php

declare(strict_types=1);

namespace Preflow\Publishing\Tests;

use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\FrozenClock;
use Preflow\Data\ChangeDates;
use Preflow\Publishing\PublishState;
use Preflow\Testing\ContextFactory;

/**
 * Change dates (ChangeDates, 0.84.0) and publishing: a published copy keeps its own (when a
 * publish wrote its content), the working copy's moves only with its content, neither counts as
 * content, and a type's own updated field is published as the working copy holds it.
 */
final class PublishedChangeDatesTest extends PublishingAppTestCase
{
    private static function at(string $at, \Closure $write): mixed
    {
        return Contexts::run(ContextFactory::forTest()->withClock(new FrozenClock(new \DateTimeImmutable($at))), $write);
    }

    private function snapshotDate(string $type, string $id, string $key = ChangeDates::FIELD): mixed
    {
        return $this->publisher()->snapshot($type, $id)?->get($key);
    }

    public function test_a_published_copy_keeps_its_own_change_date(): void
    {
        $working = self::at('2026-03-01T08:00:00Z', fn () => $this->working('post', 'p1', ['title' => ['en' => 'Hello']]));
        self::at('2026-03-02T08:00:00Z', fn () => $this->publisher()->publish($this->stored('post', 'p1'), 'ana'));

        $this->assertSame('2026-03-02T08:00:00+00:00', $this->snapshotDate('post', 'p1'), 'the publish that wrote it');
        $this->assertSame('2026-03-01T08:00:00+00:00', $this->stored('post', 'p1')->get(ChangeDates::FIELD), 'a publish of unchanged content leaves the working copy\'s date');
        $this->assertSame(PublishState::Published, $this->publisher()->state($this->stored('post', 'p1')), 'the dates differ, the content does not');
        $this->assertSame([], $this->publisher()->changes($this->stored('post', 'p1')));

        self::at('2026-03-03T08:00:00Z', fn () => $this->publisher()->publish($this->stored('post', 'p1'), 'ana'));
        $this->assertSame('2026-03-02T08:00:00+00:00', $this->snapshotDate('post', 'p1'), 'publishing the same content again keeps it');

        $edited = $this->stored('post', 'p1');
        $edited->set('title', ['en' => 'Hello again']);
        self::at('2026-03-04T08:00:00Z', fn () => $this->publisher()->saveDraft($edited, null));
        $this->assertSame('2026-03-04T08:00:00+00:00', $this->stored('post', 'p1')->get(ChangeDates::FIELD));
        $this->assertSame('2026-03-02T08:00:00+00:00', $this->snapshotDate('post', 'p1'), 'a draft leaves the published copy');

        self::at('2026-03-05T08:00:00Z', fn () => $this->publisher()->publish($this->stored('post', 'p1'), 'ana'));
        $this->assertSame('2026-03-05T08:00:00+00:00', $this->snapshotDate('post', 'p1'));
        $this->assertSame('2026-03-04T08:00:00+00:00', $this->stored('post', 'p1')->get(ChangeDates::FIELD));
        $this->assertNotNull($working);
    }

    public function test_a_types_own_updated_field_is_published_as_the_working_copy_holds_it(): void
    {
        $this->write('config/models/memo.json', (string) json_encode(['key' => 'memo', 'table' => 'memo', 'storage' => 'json', 'publishing' => true, 'fields' => [
            'title' => ['type' => 'string'],
            'updated_at' => ['type' => 'date'],
        ]]));
        $this->reboot();
        self::at('2026-03-01T08:00:00Z', fn () => $this->working('memo', 'm1', ['title' => 'First']));
        self::at('2026-03-02T08:00:00Z', fn () => $this->publisher()->publish($this->stored('memo', 'm1'), 'ana'));

        $this->assertSame('2026-03-01T08:00:00+00:00', $this->snapshotDate('memo', 'm1', 'updated_at'), 'content: the working copy\'s');
        $this->assertNull($this->snapshotDate('memo', 'm1'), 'no second key');
    }
}
