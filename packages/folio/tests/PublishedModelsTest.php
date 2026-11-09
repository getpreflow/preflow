<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Migration\MigrationRegistry;
use Preflow\Folio\Publishing\PublishedModels;
use Preflow\Folio\Tests\Fixtures\Models\Job;
use Preflow\Folio\Tests\Fixtures\Models\Poster;
use Preflow\Folio\Tests\Support\PublishingScaffold;

/** App code reads the published copies of a class-backed type as read-only objects of its class. */
final class PublishedModelsTest extends TestCase
{
    use PublishingScaffold;

    protected function setUp(): void
    {
        $this->scaffoldPublishing('sqlite', [__DIR__ . '/Fixtures/Models']);
        $this->write('config/models/job.json', (string) json_encode(['key' => 'job', 'label' => 'Jobs', 'publishing' => true]));
        $this->write('config/models/poster.json', (string) json_encode(['key' => 'poster', 'label' => 'Posters']));
        ($this->app()->container()->get(MigrationRegistry::class)->steps()['folio.tables'] ?? throw new \RuntimeException('step not registered'))();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    public function test_a_draft_only_record_is_not_found(): void
    {
        $id = $this->job('Carpenter');

        $this->assertNull($this->published()->find(Job::class, $id));
        $this->assertSame([], $this->published()->query(Job::class)->get()->items());
    }

    public function test_published_records_are_read_only_objects_of_the_class(): void
    {
        $carpenter = $this->job('Carpenter');
        $this->job('Plumber');
        $this->publish($carpenter);

        $app = $this->app();
        $published = $app->container()->get(PublishedModels::class);
        $found = $published->find(Job::class, $carpenter);
        $jobs = $published->query(Job::class)->where('title', 'Carpenter')->get()->items();

        $this->assertInstanceOf(Job::class, $found);
        $this->assertSame('Carpenter', $found->title);
        $this->assertCount(1, $jobs);
        $this->assertInstanceOf(Job::class, $jobs[0]);
        $this->assertSame($carpenter, $jobs[0]->uuid);
        $dm = $app->container()->get(\Preflow\Data\DataManager::class);
        $this->assertTrue($dm->isReadOnly($found));
        $this->assertTrue($dm->isReadOnly($jobs[0]));
        $this->expectException(\LogicException::class);
        $dm->save($found);
    }

    public function test_previews_read_the_working_copies_as_live_content_does(): void
    {
        $id = $this->job('Carpenter');
        $this->publish($id);
        $dm = $this->dm();
        $draft = $dm->find(Job::class, $id);
        $draft->title = 'Draft title';
        $dm->save($draft);

        $app = $this->app();
        $published = $app->container()->get(PublishedModels::class);
        $this->assertSame('Carpenter', $published->find(Job::class, $id)?->title, 'outside a preview: the published copy');

        $app->container()->get(\Preflow\Folio\Content\LiveContent::class)->showWorkingCopies();
        $found = $published->find(Job::class, $id);
        $this->assertSame('Draft title', $found?->title, 'in a preview: the working copy');
        $this->assertSame(['Draft title'], array_map(static fn (Job $job): string => $job->title, $published->query(Job::class)->get()->items()));
        $this->assertTrue($app->container()->get(\Preflow\Data\DataManager::class)->isReadOnly($found), 'still read-only');
    }

    public function test_a_type_without_publishing_reads_its_own_table_as_live_content_does(): void
    {
        $poster = new Poster();
        $poster->name = 'Summer fair';
        $this->dm()->save($poster);

        $app = $this->app();
        $published = $app->container()->get(PublishedModels::class);
        $found = $published->find(Poster::class, (string) $poster->uuid);
        $listed = $published->query(Poster::class)->get()->items();

        $this->assertSame('Summer fair', $found?->name, 'no published copies: the record itself, as LiveContent reads it');
        $this->assertSame([(string) $poster->uuid], array_map(static fn (Poster $p): string => (string) $p->uuid, $listed));
        $this->assertTrue($app->container()->get(\Preflow\Data\DataManager::class)->isReadOnly($found), 'still read-only');
    }

    public function test_a_class_that_is_not_a_type_is_refused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(\Preflow\Folio\Tests\Fixtures\Models\NotAType::class . ' is not a type');

        $this->published()->query(\Preflow\Folio\Tests\Fixtures\Models\NotAType::class);
    }

    private function published(): PublishedModels
    {
        return $this->app()->container()->get(PublishedModels::class);
    }

    /** Saves a job from app code: a working copy only. */
    private function job(string $title): string
    {
        $job = new Job();
        $job->title = $title;
        $this->dm()->save($job);

        return (string) $job->uuid;
    }

    private function publish(string $id): void
    {
        $this->publisher()->publish($this->stored('job', $id), 'tester');
    }
}
