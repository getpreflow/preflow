<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Publishing;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Publishing\Publisher;
use Preflow\Folio\Publishing\Scheduler;
use Preflow\Folio\Tests\Support\PublishingScaffold;

final class SchedulerTest extends TestCase
{
    use PublishingScaffold;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    public function test_runs_due_items_once(): void
    {
        $this->working('post', 'due', ['title' => ['en' => 'Due'], 'slug' => ['en' => 'due'], '_publish_at' => '2026-10-20T09:00']);
        $this->working('post', 'later', ['title' => ['en' => 'Later'], 'slug' => ['en' => 'later'], '_publish_at' => '2026-10-21T09:00']);
        $now = new \DateTimeImmutable('2026-10-20T10:00');

        $first = $this->scheduler()->runDue($now);
        $this->assertSame(['post/due'], $first['published']);
        $this->assertSame([], $first['failed']);
        $this->assertNotNull($this->publisher()->snapshot('post', 'due'));
        $this->assertSame('scheduler', $this->publisher()->snapshot('post', 'due')?->get('_published_by'));
        $this->assertNull($this->publisher()->snapshot('post', 'later'));

        $this->assertSame(['published' => [], 'failed' => []], $this->scheduler()->runDue($now));
    }

    public function test_failing_due_item_stays_scheduled(): void
    {
        $this->working('post', 'bad', ['title' => ['en' => ''], 'slug' => ['en' => 'bad'], '_publish_at' => '2026-10-20T09:00']);

        $result = $this->scheduler()->runDue(new \DateTimeImmutable('2026-10-20T10:00'));

        $this->assertSame([], $result['published']);
        $this->assertArrayHasKey('post/bad', $result['failed']);
        $this->assertSame('2026-10-20T09:00', $this->stored('post', 'bad')->get('_publish_at'));
    }

    private function scheduler(): Scheduler
    {
        $c = $this->app()->container();

        return new Scheduler($c->get(Publisher::class), $c->get(TypeCatalog::class), $c->get(DataManager::class));
    }
}
