<?php

declare(strict_types=1);

namespace Tests\Api;

use PHPUnit\Framework\TestCase;
use Tests\Support\StarterApp;

final class CompaniesApiTest extends TestCase
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

    public function test_list_has_open_job_counts(): void
    {
        $response = $this->json('GET', '/api/companies');

        $response->assertOk();
        $companies = $response->json('data');
        $this->assertCount(7, $companies);
        $this->assertSame(['id', 'slug', 'name', 'place', 'website', 'summary', 'open_jobs'], array_keys($companies[0]));

        // Open jobs are published jobs: the counts add up to the public total, drafts left out.
        $this->assertSame(34, array_sum(array_column($companies, 'open_jobs')));
        $this->assertArrayNotHasKey('jobs', $companies[0]);
    }

    public function test_show_includes_jobs(): void
    {
        $response = $this->json('GET', '/api/companies/northwind-labs');

        $response->assertOk();
        $company = $response->json('data');
        $this->assertSame('Northwind Labs', $company['name']);
        $this->assertCount($company['open_jobs'], $company['jobs']);
        $this->assertGreaterThan(0, $company['open_jobs']);
        foreach ($company['jobs'] as $job) {
            $this->assertSame('northwind-labs', $job['company']['slug']);
            $this->assertArrayNotHasKey('status', $job);
        }

        $this->assertSame(404, $this->json('GET', '/api/companies/nope')->status());
    }
}
