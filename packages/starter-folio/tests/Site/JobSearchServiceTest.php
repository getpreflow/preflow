<?php

declare(strict_types=1);

namespace Tests\Site;

use App\Jobs\JobFilters;
use App\Jobs\JobSearch;
use App\Models\Company;
use App\Models\Job;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Folio\Publishing\Publisher;
use Tests\Support\StarterApp;

final class JobSearchServiceTest extends TestCase
{
    use StarterApp;

    private DataManager $data;
    private JobSearch $search;

    protected function setUp(): void
    {
        $this->bootStarter();
        $this->data = $this->container()->get(DataManager::class);
        $this->search = $this->container()->get(JobSearch::class);
    }

    protected function tearDown(): void
    {
        $this->removeStarter();
    }

    /** @return list<string> titles */
    private function titles(JobFilters $filters): array
    {
        return array_map(static fn (Job $job): string => $job->title, $this->search->search($filters, 100)->items);
    }

    public function test_q_and_a_filter_both_apply(): void
    {
        $jobs = $this->search->search(JobFilters::fromArray(['q' => 'developer', 'remote' => 'full']), 100)->items;
        $titles = array_map(static fn (Job $job): string => $job->title, $jobs);

        $this->assertNotEmpty($jobs);
        foreach ($jobs as $job) {
            $this->assertSame('full', $job->remote);
            $this->assertStringContainsStringIgnoringCase('developer', $job->title . ' ' . $job->description);
        }
        // Each condition alone matches more.
        $this->assertGreaterThan(count($titles), count($this->titles(JobFilters::fromArray(['q' => 'developer']))));
        $this->assertGreaterThan(count($titles), count($this->titles(JobFilters::fromArray(['remote' => 'full']))));
    }

    public function test_like_wildcards_in_q_match_literally(): void
    {
        $this->assertSame([], $this->titles(JobFilters::fromArray(['q' => '%'])));
        $this->assertSame([], $this->titles(JobFilters::fromArray(['q' => '_'])));
        $this->assertSame([], $this->titles(JobFilters::fromArray(['q' => 'Dev%per'])));

        // A literal % is still found.
        $job = $this->data->query(Job::class)->where('title', 'Illustrator')->first();
        $job->title = 'Illustrator (100% remote)';
        $this->data->save($job);
        $this->container()->get(Publisher::class)->publish($this->data->findType('job', (string) $job->uuid), 'test');
        $this->assertSame(['Illustrator (100% remote)'], $this->titles(JobFilters::fromArray(['q' => '100%'])));
    }

    public function test_open_jobs_of_leaves_out_the_excluded_job(): void
    {
        $company = $this->data->query(Company::class)->where('name', 'Lumen Harbor')->first();
        $all = $this->search->openJobsOf($company->uuid);
        $this->assertGreaterThan(1, count($all));
        $excluded = $all[0]->uuid;

        $others = $this->search->openJobsOf($company->uuid, $excluded);

        $this->assertCount(count($all) - 1, $others);
        $this->assertNotContains($excluded, array_map(static fn (Job $job): string => $job->uuid, $others));
    }
}
