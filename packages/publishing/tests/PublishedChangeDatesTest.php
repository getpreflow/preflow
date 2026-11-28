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
 * content, and a type's own updated field is content the data layer never writes.
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

    public function test_unchanged_publish_without_updated_at_keeps_the_date(): void
    {
        self::at('2026-03-01T08:00:00Z', fn () => $this->working('post', 'p1', ['title' => ['en' => 'Hello']]));
        $stored = $this->stored('post', 'p1');
        $workingDate = $stored->get(ChangeDates::FIELD);
        // A record built in PHP, without the change date the stored copy has.
        $built = static fn (): \Preflow\Data\DynamicRecord => \Preflow\Data\DynamicRecord::fromArray($stored->getType(), ['uuid' => 'p1', 'title' => ['en' => 'Hello']]);

        self::at('2026-03-02T08:00:00Z', fn () => $this->publisher()->publish($built(), 'ana'));
        $version = $this->stored('post', 'p1')->get('_version');
        $published = $this->snapshotDate('post', 'p1');
        self::at('2026-03-03T08:00:00Z', fn () => $this->publisher()->publish($built(), 'ana'));

        $this->assertSame($workingDate, $this->stored('post', 'p1')->get(ChangeDates::FIELD), 'the working copy keeps its date');
        $this->assertSame($published, $this->snapshotDate('post', 'p1'), 'so does the published copy');
        $this->assertSame($version, $this->stored('post', 'p1')->get('_version'), 'no change, no new version');
    }

    public function test_a_failed_publish_leaves_the_record_without_the_stored_date(): void
    {
        self::at('2026-03-01T08:00:00Z', fn () => $this->working('post', 'p1', ['title' => ['en' => 'Hello']]));
        $built = \Preflow\Data\DynamicRecord::fromArray($this->stored('post', 'p1')->getType(), ['uuid' => 'p1', 'title' => ['en' => 'Hello']]);

        try {
            self::at('2026-03-02T08:00:00Z', fn () => $this->publisher()->publish($built, 'ana', 99));
            $this->fail('published with a stale version');
        } catch (\Preflow\Publishing\VersionConflict) {
        }

        $this->assertNull($built->get(ChangeDates::FIELD), 'the date taken for an attempt is not left on the caller\'s record');
        $this->assertSame('2026-03-01T08:00:00+00:00', $this->stored('post', 'p1')->get(ChangeDates::FIELD));
    }

    public function test_a_types_own_updated_field_is_content_and_each_copy_has_its_own_change_date(): void
    {
        $this->write('config/models/memo.json', (string) json_encode(['key' => 'memo', 'table' => 'memo', 'storage' => 'json', 'publishing' => true, 'fields' => [
            'title' => ['type' => 'string'],
            'updated_at' => ['type' => 'date'],
        ]]));
        $this->reboot();
        self::at('2026-03-01T08:00:00Z', fn () => $this->working('memo', 'm1', ['title' => 'First', 'updated_at' => '2020-05-05']));
        self::at('2026-03-02T08:00:00Z', fn () => $this->publisher()->publish($this->stored('memo', 'm1'), 'ana'));

        $this->assertSame('2020-05-05', $this->snapshotDate('memo', 'm1', 'updated_at'), 'published as the working copy holds it');
        $this->assertSame('2020-05-05', $this->stored('memo', 'm1')->get('updated_at'));
        $this->assertSame('2026-03-02T08:00:00+00:00', $this->snapshotDate('memo', 'm1'));
        $this->assertSame('2026-03-01T08:00:00+00:00', $this->stored('memo', 'm1')->get(ChangeDates::FIELD));
    }

    public function test_migrate_dates_an_old_published_copy_by_its_publish_time(): void
    {
        $driver = $this->dm()->driver('json');
        // A copy from before 0.84 the activity log no longer knows: no change date, its publish stamp only.
        $driver->save('post__published', 'p2', ['title' => ['en' => 'Old'], 'uuid' => 'p2', '_published_at' => '2025-05-05T07:05:05+02:00']);

        $steps = $this->app()->container()->get(\Preflow\Data\Migration\MigrationRegistry::class)->steps(sql: false);
        $lines = self::at('2026-11-22T12:00:00Z', static fn (): array => $steps['data.change-dates']());

        $this->assertContains('gave 1 record of post__published a change date (1 from the publish time)', $lines);
        $this->assertSame('2025-05-05T05:05:05+00:00', $driver->findOne('post__published', 'p2')[ChangeDates::FIELD] ?? null);
    }
}
