<?php

declare(strict_types=1);

namespace Tests\Site;

use App\Models\Company;
use App\Models\Job;
use App\Models\User;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Folio\Publishing\PublishedModels;
use Tests\Support\StarterApp;

/** The page cache as production runs it: APP_DEBUG=0 (it is off while debugging). */
final class PageCacheTest extends TestCase
{
    use StarterApp;

    protected function setUp(): void
    {
        // The demo seeder refuses to run with debug off, so seed first, then run as production does.
        // Each request boots its own app, so it reads the changed setting.
        $this->bootStarter();
        $this->setEnv('APP_DEBUG', '0');
        $this->asWeb = true;
    }

    protected function tearDown(): void
    {
        $this->removeStarter();
    }

    private function data(): DataManager
    {
        return $this->container()->get(DataManager::class);
    }

    public function test_second_visit_is_a_cache_hit(): void
    {
        $this->get('/jobs')->assertHeader('X-Page-Cache', 'miss');

        $this->get('/jobs')->assertHeader('X-Page-Cache', 'hit');
    }

    public function test_publishing_refreshes_list_detail_and_company_page(): void
    {
        $data = $this->data();
        $owner = $data->query(User::class)->where('email', 'employer@example.com')->first();
        $company = $data->find(Company::class, (string) $owner->company);
        $neighbour = $this->container()->get(PublishedModels::class)->query(Job::class)->where('company', $company->uuid)->first();

        $pages = ['/jobs', '/companies/' . $company->slug, '/jobs/' . $neighbour->slug];
        foreach ($pages as $path) {
            $this->assertStringNotContainsString('Cache Test Engineer', $this->get($path)->body(), $path);
            $this->get($path)->assertHeader('X-Page-Cache', 'hit');
        }

        // The employer's own steps: sign in, post a draft, publish it, take it down.
        $cookies = $this->signInEmployer();
        $created = $this->employerPost($cookies, '/employer/jobs', [
            'title' => 'Cache Test Engineer', 'place' => 'Berlin', 'employmentType' => 'full_time', 'remote' => 'hybrid',
            'description' => 'You keep the pages fast and fresh, and you know when a cached page is stale.',
        ]);
        $this->assertSame(302, $created->status());
        $job = $data->query(Job::class)->where('title', 'Cache Test Engineer')->first();
        foreach ($pages as $path) {
            $this->assertStringNotContainsString('Cache Test Engineer', $this->get($path)->body(), 'a draft is on no page: ' . $path);
        }

        $this->employerPost($cookies, '/employer/jobs/' . $job->uuid . '/publish');

        // Nothing is cleared by hand: the cache refreshes itself.
        foreach ($pages as $path) {
            $this->assertStringContainsString('Cache Test Engineer', $this->get($path)->body(), $path);
        }
        $this->get('/jobs/' . $job->slug)->assertOk()->assertSee('Cache Test Engineer');

        $this->employerPost($cookies, '/employer/jobs/' . $job->uuid . '/unpublish');
        foreach ($pages as $path) {
            $this->assertStringNotContainsString('Cache Test Engineer', $this->get($path)->body(), 'taken down: ' . $path);
        }
    }

    /**
     * @param array<string, string> $cookies
     * @param array<string, string> $body
     */
    private function employerPost(array $cookies, string $uri, array $body = []): \Preflow\Testing\TestResponse
    {
        $headers = ['Cookie' => $this->cookieHeader($cookies)];
        preg_match('/name="_csrf_token" value="([^"]+)"/', $this->get('/employer/jobs/new', $headers)->body(), $match);

        return $this->post($uri, $body + ['_csrf_token' => html_entity_decode($match[1] ?? '')], $headers);
    }

    public function test_signed_in_employer_bypasses_cache(): void
    {
        $this->get('/jobs');
        $this->get('/jobs')->assertHeader('X-Page-Cache', 'hit');
        $cookies = $this->signInEmployer();

        $response = $this->get('/jobs', ['Cookie' => $this->cookieHeader($cookies)]);

        $response->assertHeader('X-Page-Cache', 'bypass');
    }

    public function test_login_page_is_never_stored(): void
    {
        $this->get('/employer/login');

        $this->assertNotSame('hit', $this->send('GET', '/employer/login')->getHeaderLine('X-Page-Cache'));
    }
}
