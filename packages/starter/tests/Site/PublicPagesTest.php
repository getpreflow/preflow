<?php

declare(strict_types=1);

namespace Tests\Site;

use App\Models\Company;
use App\Models\Job;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Tests\Support\StarterApp;

final class PublicPagesTest extends TestCase
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

    private function jobTitled(string $title): Job
    {
        $job = $this->data()->query(Job::class)->where('title', $title)->first();
        $this->assertNotNull($job, $title);

        return $job;
    }

    private function jobLinks(string $html): int
    {
        return preg_match_all('#href="/jobs/[a-z0-9-]+"#', $html);
    }

    public function test_home_shows_latest_six_jobs_and_error_boundary_preview(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $this->assertSame(6, $this->jobLinks($response->body()));
        $response->assertSee('Error Boundary');
        $response->assertSee('href="/employer"');
        $response->assertSee('href="/api/jobs"');
    }

    public function test_job_detail_shows_job_company_and_more_from_company(): void
    {
        $job = $this->jobTitled('Frontend Developer');

        $response = $this->get('/jobs/' . $job->slug);

        $response->assertOk();
        $response->assertSee('<h1');
        $response->assertSee('Frontend Developer');
        $response->assertSee('Lumen Harbor');
        $response->assertSee('href="/companies/lumen-harbor"');
        $response->assertSee('<strong>Frontend Developer</strong>'); // the Markdown description
        $response->assertSee('More from Lumen Harbor');
        // Lumen Harbor has more open jobs; this one is not repeated in the list.
        $this->assertSame(0, substr_count($response->body(), 'href="/jobs/' . $job->slug . '"'));
        $this->assertGreaterThan(0, $this->jobLinks($response->body()));
        $this->assertLessThanOrEqual(4, $this->jobLinks($response->body()));
    }

    public function test_unknown_job_is_404_with_page(): void
    {
        $response = $this->get('/jobs/no-such-job');

        $response->assertStatus(404);
        $response->assertSee('Job not found');
        $response->assertSee('Preflow Jobs'); // still the full layout
    }

    public function test_draft_job_is_404(): void
    {
        $draft = $this->data()->query(Job::class)->where('status', 'draft')->first();
        $this->assertNotNull($draft);

        $this->get('/jobs/' . $draft->slug)->assertStatus(404);
    }

    public function test_company_page_lists_open_jobs_only(): void
    {
        $company = $this->data()->query(Company::class)->where('name', 'Northwind Labs')->first();
        $drafts = $this->data()->query(Job::class)->where('status', 'draft')->where('company', $company->uuid)->get()->items();
        $this->assertNotEmpty($drafts);

        $response = $this->get('/companies/northwind-labs');

        $response->assertOk();
        $response->assertSee('Northwind Labs');
        $this->assertSame(2, $this->jobLinks($response->body())); // two published, four drafts
        foreach ($drafts as $draft) {
            $response->assertNotSee('href="/jobs/' . $draft->slug . '"');
        }
        $this->get('/companies/nobody')->assertStatus(404);
    }

    public function test_markdown_description_escapes_html(): void
    {
        $job = $this->jobTitled('Technical Recruiter');
        $job->description = "Hello <script>alert(1)</script> and [x](javascript:alert(1)) with **bold** text.";
        $this->data()->save($job);

        $response = $this->get('/jobs/' . $job->slug);

        $response->assertOk();
        $response->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;');
        $response->assertNotSee('<script>alert(1)');
        $response->assertNotSee('javascript:');
        $response->assertSee('<strong>bold</strong>');
    }

    public function test_pages_are_usable_on_phone_width(): void
    {
        $this->get('/')->assertSee('<meta name="viewport" content="width=device-width, initial-scale=1">');
    }
}
