<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Publishing\PublishedModels;
use Preflow\Folio\Tests\Fixtures\Models\Job;
use Preflow\Folio\Tests\Support\StaffScaffold;

/**
 * Staff edit a model class through Folio like a JSON type (its admin file config/models/job.json),
 * app code reads what they saved through the class, and the published copy through PublishedModels.
 * One signed-in app handles each flow (the native session), so the tests run in separate processes.
 */
final class ClassTypeAdminAppTest extends TestCase
{
    use StaffScaffold;

    protected function setUp(): void
    {
        $this->staffSite(
            data: ['model_paths' => [__DIR__ . '/Fixtures/Models']],
            models: ['job' => ['label' => 'Jobs', 'publishing' => true, 'list' => ['columns' => ['title', 'company']]]],
        );
    }

    protected function tearDown(): void
    {
        $this->removeSite();
    }

    #[RunInSeparateProcess]
    public function test_staff_create_edit_and_publish_a_class_backed_record(): void
    {
        $uuid = $this->createJob('Carpenter');
        $this->assertStringContainsString('Carpenter', $this->html('/folio/job'), 'the list shows the new record');

        $this->assertSame(302, $this->post("/folio/job/{$uuid}", ['title' => 'Senior carpenter', 'company' => 'Holz AG', '_version' => (string) $this->version($uuid), '_action' => 'publish'])->getStatusCode());

        $job = $this->dm()->find(Job::class, $uuid);
        $this->assertInstanceOf(Job::class, $job);
        $this->assertSame('Senior carpenter', $job->title, 'the app reads what staff saved');
        $published = $this->flow->container()->get(PublishedModels::class)->find(Job::class, $uuid);
        $this->assertInstanceOf(Job::class, $published);
        $this->assertSame('Senior carpenter', $published->title);
        $this->assertSame('Holz AG', $published->company);
    }

    #[RunInSeparateProcess]
    public function test_a_draft_change_is_what_app_code_reads_while_the_published_copy_stays(): void
    {
        $uuid = $this->createJob('Carpenter');
        $this->post("/folio/job/{$uuid}", ['title' => 'Carpenter', '_version' => (string) $this->version($uuid), '_action' => 'publish']);

        $this->assertSame(302, $this->post("/folio/job/{$uuid}", ['title' => 'Draft title', '_version' => (string) $this->version($uuid)])->getStatusCode());

        $this->assertSame('Draft title', $this->dm()->find(Job::class, $uuid)?->title, 'app code reads the working copy, as findType() does');
        $this->assertSame('Carpenter', $this->flow->container()->get(PublishedModels::class)->find(Job::class, $uuid)?->title);
    }

    #[RunInSeparateProcess]
    public function test_an_app_save_does_not_disturb_publishing(): void
    {
        $uuid = $this->createJob('Carpenter');
        $this->post("/folio/job/{$uuid}", ['title' => 'Carpenter', '_version' => (string) $this->version($uuid), '_action' => 'publish']);
        $version = $this->version($uuid);
        $this->assertStringContainsString('folio-badge is-published', $this->html("/folio/job/{$uuid}/edit"), 'published, nothing changed yet');

        $job = $this->dm()->find(Job::class, $uuid);
        $job->title = 'Joiner';
        $this->dm()->save($job);

        $this->assertSame('Carpenter', $this->flow->container()->get(PublishedModels::class)->find(Job::class, $uuid)?->title, 'the published copy keeps its title');
        $this->assertSame($version, $this->version($uuid), 'the app save leaves _version alone');
        $edit = $this->html("/folio/job/{$uuid}/edit");
        $this->assertStringContainsString('<span class="folio-badge is-changed">Changed</span>', $edit, 'Folio shows unpublished changes');
        $this->assertStringContainsString('value="Joiner"', $edit);
        $this->assertMatchesRegularExpression('~<span class="folio-badge is-changed">Changed</span>~', $this->html('/folio/job'), 'in the list too');
    }

    #[RunInSeparateProcess]
    public function test_the_labels_of_a_class_backed_type_are_translatable(): void
    {
        $keys = $this->flow->container()->get(\Preflow\I18n\DerivedKeys::class)->all();

        $this->assertSame('Jobs', $keys[\Preflow\Data\ModelLabelKeys::type('site', 'job')]['value'] ?? null, 'the admin file\'s label');
        $this->assertArrayHasKey(\Preflow\Data\ModelLabelKeys::field('site', 'job', 'company'), $keys, 'a field only the class declares');
    }

    /** Signs in and posts the create form; returns the new record's id. */
    private function createJob(string $title): string
    {
        $this->post('/folio/login', ['email' => self::CONFIG_EMAIL, 'password' => self::PASSWORD]);
        $this->assertStringContainsString('action="/folio/job"', $this->html('/folio/job/new'), 'the create form');

        $response = $this->post('/folio/job', ['title' => $title]);
        $this->assertSame(302, $response->getStatusCode(), (string) $response->getBody());
        $job = $this->dm()->query(Job::class)->where('title', $title)->first();
        $this->assertInstanceOf(Job::class, $job);

        return (string) $job->uuid;
    }

    private function version(string $uuid): int
    {
        return (int) ($this->dm()->findType('job', $uuid)?->get('_version') ?? 0);
    }
}
