<?php

declare(strict_types=1);

namespace Tests\Api;

use App\Models\Job;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Folio\Publishing\PublishState;
use Preflow\Folio\Publishing\Publisher;
use Tests\Support\StarterApp;

/** The public read side of the API. Nothing here renders HTML. */
final class JobsApiTest extends TestCase
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

    private function jobWhere(string $field, string $value): Job
    {
        $job = $this->container()->get(DataManager::class)->query(Job::class)->where($field, $value)->first();
        $this->assertInstanceOf(Job::class, $job);

        return $job;
    }

    public function test_list_with_filters_and_meta(): void
    {
        $all = $this->json('GET', '/api/jobs');
        $all->assertOk();
        $this->assertSame(12, $all->json('meta.per_page'));
        $this->assertSame(1, $all->json('meta.page'));
        $this->assertSame(34, $all->json('meta.total'));
        $this->assertSame(3, $all->json('meta.last_page'));
        $this->assertCount(12, $all->json('data'));

        $first = $all->json('data.0');
        $this->assertSame(
            ['id', 'slug', 'title', 'company', 'place', 'employment_type', 'remote', 'salary', 'description', 'published_at', 'url'],
            array_keys($first),
            'status is only for token-authenticated responses',
        );
        $this->assertSame(['id', 'slug', 'name'], array_keys($first['company']));
        $this->assertStringStartsWith('http://localhost:8080/jobs/', $first['url']);

        $filtered = $this->json('GET', '/api/jobs?type=contract&remote=full');
        $filtered->assertOk();
        $this->assertGreaterThan(0, $filtered->json('meta.total'));
        $this->assertLessThan(34, $filtered->json('meta.total'));
        foreach ($filtered->json('data') as $job) {
            $this->assertSame('contract', $job['employment_type']);
            $this->assertSame('full', $job['remote']);
        }
    }

    public function test_paging_past_last_page_is_empty_with_meta(): void
    {
        $page3 = $this->json('GET', '/api/jobs?page=3');
        $this->assertCount(10, $page3->json('data'));

        $past = $this->json('GET', '/api/jobs?page=999');
        $past->assertOk();
        $this->assertSame([], $past->json('data'));
        $this->assertSame(34, $past->json('meta.total'));
        $this->assertSame(3, $past->json('meta.last_page'));
        $this->assertSame(999, $past->json('meta.page'));
    }

    public function test_hostile_filters_are_ignored(): void
    {
        foreach ([
            '?type=%3Cscript%3E',
            '?remote[]=x',
            '?page=-3',
            '?page=abc',
            '?q[]=a&place[]=b',
            '?q=' . str_repeat('x', 300),
        ] as $query) {
            $this->json('GET', '/api/jobs' . $query)->assertOk();
        }

        $this->assertSame(34, $this->json('GET', '/api/jobs?type=%3Cscript%3E')->json('meta.total'));
        $this->assertSame(1, $this->json('GET', '/api/jobs?page=-3')->json('meta.page'));
    }

    public function test_show_published_job(): void
    {
        $job = $this->jobWhere('title', 'Frontend Developer');

        $response = $this->json('GET', '/api/jobs/' . $job->uuid);

        $response->assertOk();
        $this->assertSame($job->uuid, $response->json('data.id'));
        $this->assertSame('Frontend Developer', $response->json('data.title'));
        $this->assertSame($job->description, $response->json('data.description'));
        $this->assertArrayNotHasKey('status', $response->json('data'));
    }

    public function test_draft_is_404_json(): void
    {
        $states = $this->container()->get(Publisher::class)->states('job');
        $draft = $this->container()->get(DataManager::class)->find(Job::class, (string) array_search(PublishState::Draft, $states, true));
        $this->assertInstanceOf(Job::class, $draft);

        $response = $this->json('GET', '/api/jobs/' . $draft->uuid);

        $this->assertSame(404, $response->status());
        $this->assertArrayHasKey('message', $response->json());
        $response->assertHeaderContains('Content-Type', 'application/json');
    }

    public function test_unknown_route_is_404_json(): void
    {
        $response = $this->json('GET', '/api/nothing-here');

        $this->assertSame(404, $response->status());
        $this->assertArrayHasKey('message', $response->json());
    }

    public function test_wrong_method_is_405_json(): void
    {
        $response = $this->json('PUT', '/api/jobs');

        $this->assertSame(405, $response->status());
        $this->assertArrayHasKey('message', $response->json());
        $response->assertHeaderContains('Allow', 'GET');
    }
}
