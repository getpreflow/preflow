<?php

declare(strict_types=1);

namespace Tests\Site;

use App\Models\Company;
use App\Models\Job;
use PHPUnit\Framework\TestCase;
use Preflow\Core\PageCache\ChangeLog;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Publishing\Publisher;
use Tests\Support\StarterApp;

/** Folio's publishing is the only draft/published state: what staff publish is what the site and the API show. */
final class PublishingTest extends TestCase
{
    use StarterApp;

    protected function setUp(): void
    {
        $this->bootStarter();
    }

    protected function tearDown(): void
    {
        $this->removeStarter();
    }

    private function data(): DataManager
    {
        return $this->container()->get(DataManager::class);
    }

    private function publisher(): Publisher
    {
        return $this->container()->get(Publisher::class);
    }

    /** A new job saved as a draft, the way Folio's form saves one (no rules, version + 1). */
    private function draft(Company $company, string $title): DynamicRecord
    {
        $record = DynamicRecord::fromArray($this->data()->getTypeRegistry()->get('job'), [
            'uuid' => md5('publishing-test-' . $title),
            'title' => $title,
            'slug' => 'test-' . md5($title),
            'company' => $company->uuid,
            'place' => 'Berlin',
            'employmentType' => 'full_time',
            'remote' => 'hybrid',
            'description' => 'You test that a job staff publish in Folio shows up on every public page.',
            // Staff set "Posted on" in Folio's form; the employer area sets it on the first publish.
            'postedOn' => (new \DateTimeImmutable())->format('Y-m-d'),
        ]);
        $this->publisher()->saveDraft($record, null);

        return $record;
    }

    private function lumen(): Company
    {
        $company = $this->data()->query(Company::class)->where('name', 'Lumen Harbor')->first();
        $this->assertInstanceOf(Company::class, $company);

        return $company;
    }

    public function test_job_published_by_editor_appears_everywhere(): void
    {
        $record = $this->draft($this->lumen(), 'Folio Published Engineer');
        $slug = (string) $record->get('slug');
        $pages = ['/jobs', '/', '/companies/lumen-harbor'];
        foreach ($pages as $path) {
            $this->get($path)->assertNotSee('Folio Published Engineer');
        }
        $this->get('/jobs/' . $slug)->assertNotFound();

        $this->publisher()->publish($record, 'editor@example.com');

        foreach ($pages as $path) {
            $this->get($path)->assertOk()->assertSee('Folio Published Engineer');
        }
        $this->get('/jobs/' . $slug)->assertOk()->assertSee('Folio Published Engineer');
        $api = $this->json('GET', '/api/jobs');
        $this->assertSame((string) $record->getId(), $api->json('data.0.id'), 'the newest job comes first');
        $this->assertSame(35, $api->json('meta.total'));
        $this->assertNotNull($api->json('data.0.published_at'));
    }

    public function test_unpublished_job_disappears(): void
    {
        $job = $this->data()->query(Job::class)->where('title', 'Frontend Developer')->first();
        $this->get('/jobs/' . $job->slug)->assertOk();

        $this->publisher()->unpublish('job', (string) $job->uuid);

        $this->get('/jobs/' . $job->slug)->assertNotFound();
        $this->get('/companies/lumen-harbor')->assertNotSee('href="/jobs/' . $job->slug . '"');
        $this->assertSame(404, $this->json('GET', '/api/jobs/' . $job->uuid)->status());
        $this->assertSame(33, $this->json('GET', '/api/jobs')->json('meta.total'));
        // Unpublished, not deleted: the working copy is still there for staff.
        $this->assertNotNull($this->data()->find(Job::class, (string) $job->uuid));
    }

    public function test_job_without_a_slug_cannot_be_published(): void
    {
        $record = $this->draft($this->lumen(), 'Slugless Engineer');
        $record->set('slug', '');
        $this->publisher()->saveDraft($record, null);

        try {
            $this->publisher()->publish($this->data()->findType('job', (string) $record->getId()), 'editor@example.com');
            $this->fail('publishing without a slug must be refused');
        } catch (\Preflow\Validation\ValidationException $e) {
            $this->assertArrayHasKey('slug', $e->result()->errors());
        }

        $this->get('/jobs')->assertNotSee('Slugless Engineer');
        $this->assertSame(34, $this->json('GET', '/api/jobs')->json('meta.total'));
    }

    public function test_working_copy_edit_is_not_public_until_published(): void
    {
        $job = $this->data()->query(Job::class)->where('title', 'Frontend Developer')->first();
        $record = $this->data()->findType('job', (string) $job->uuid);
        $this->assertSame('published', $this->publisher()->state($record)->value);

        $record->set('title', 'Frontend Developer (edited)');
        $this->publisher()->saveDraft($record, null);

        $this->assertSame('changed', $this->publisher()->state($this->data()->findType('job', (string) $job->uuid))->value);
        $this->get('/jobs/' . $job->slug)->assertOk()->assertNotSee('Frontend Developer (edited)');
        $this->assertSame('Frontend Developer', $this->json('GET', '/api/jobs/' . $job->uuid)->json('data.title'));

        $this->publisher()->publish($this->data()->findType('job', (string) $job->uuid), 'editor@example.com');

        $this->get('/jobs/' . $job->slug)->assertSee('Frontend Developer (edited)');
        $this->assertSame('Frontend Developer (edited)', $this->json('GET', '/api/jobs/' . $job->uuid)->json('data.title'));
    }

    public function test_employer_publish_goes_through_folio(): void
    {
        $cookies = $this->signInEmployer();
        $form = $this->get('/employer/jobs/new', ['Cookie' => $this->cookieHeader($cookies)])->body();
        preg_match('/name="_csrf_token" value="([^"]+)"/', $form, $match);
        $token = html_entity_decode($match[1] ?? '');
        $this->post('/employer/jobs', [
            'title' => 'Folio Snapshot Engineer', 'place' => 'Berlin', 'employmentType' => 'full_time', 'remote' => 'no',
            'description' => 'You check that an employer publish writes the same snapshot staff would.',
            '_csrf_token' => $token,
        ], ['Cookie' => $this->cookieHeader($cookies)]);
        $job = $this->data()->query(Job::class)->where('title', 'Folio Snapshot Engineer')->first();
        $this->assertInstanceOf(Job::class, $job);
        $this->assertNull($this->publisher()->snapshot('job', (string) $job->uuid), 'a new job is a draft');

        $this->post('/employer/jobs/' . $job->uuid . '/publish', ['_csrf_token' => $token], ['Cookie' => $this->cookieHeader($cookies)])
            ->assertRedirect('/employer');

        $snapshot = $this->publisher()->snapshot('job', (string) $job->uuid);
        $this->assertNotNull($snapshot);
        $this->assertSame('Folio Snapshot Engineer', $snapshot->get('title'));
        $this->assertSame('employer:employer@example.com', $snapshot->get('_published_by'));
        $revisions = $this->publisher()->revisions('job', (string) $job->uuid);
        $this->assertCount(1, $revisions);
        $this->assertSame('employer:employer@example.com', $revisions[0]->createdBy);
        $this->get('/employer', ['Cookie' => $this->cookieHeader($cookies)])->assertSee('employer__status--published');
    }

    public function test_jobs_page_intro_shows_after_publish(): void
    {
        // As production runs: the page cache on (it is off while debugging).
        $this->setEnv('APP_DEBUG', '0');
        $this->asWeb = true;
        $this->get('/jobs');
        $this->get('/jobs')->assertHeader('X-Page-Cache', 'hit');

        $record = $this->data()->findType('jobs_page', 'jobs_page');
        $this->assertNotNull($record, 'the content seed made the page part');
        $record->set('intro', '<p>We are hiring <strong>everywhere</strong>.</p>');
        $this->publisher()->saveDraft($record, null);
        $this->get('/jobs')->assertNotSee('We are hiring');

        $this->publisher()->publish($this->data()->findType('jobs_page', 'jobs_page'), 'editor@example.com');
        // A publish in Folio's admin hands its changes to the page cache when its request ends;
        // this one ran outside a request, so it does that step itself.
        $this->container()->get(ChangeLog::class)->flush();

        // Nothing is cleared by hand: the publish refreshes the cached page.
        $this->get('/jobs')->assertSee('We are hiring <strong>everywhere</strong>.');
    }

    public function test_page_parts_show_on_job_and_company_pages(): void
    {
        $job = $this->data()->query(Job::class)->where('title', 'Frontend Developer')->first();
        $parts = [
            'job_page' => ['cta_title' => 'Ready to apply?', 'cta_text' => '<p>Write to the company directly.</p>'],
            'company_page' => ['before_jobs' => '<p>Every job here is open now.</p>'],
        ];
        foreach ($parts as $type => $values) {
            $record = $this->data()->findType($type, $type);
            foreach ($values as $field => $value) {
                $record->set($field, $value);
            }
            $this->publisher()->publish($record, 'editor@example.com');
        }

        $this->get('/jobs/' . $job->slug)->assertSee('Ready to apply?')->assertSee('<p>Write to the company directly.</p>');
        $this->get('/companies/lumen-harbor')->assertSee('<p>Every job here is open now.</p>');
    }
}
