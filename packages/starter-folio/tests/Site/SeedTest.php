<?php

declare(strict_types=1);

namespace Tests\Site;

use App\Models\Company;
use App\Models\Job;
use App\Models\User;
use App\Seeds\DemoSeeder;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Tests\Support\StarterApp;

final class SeedTest extends TestCase
{
    use StarterApp;

    protected function tearDown(): void
    {
        $this->removeStarter();
    }

    private function data(): DataManager
    {
        return $this->container()->get(DataManager::class);
    }

    private function total(string $class, array $where = []): int
    {
        $query = $this->data()->query($class);
        foreach ($where as $field => $value) {
            $query->where($field, $value);
        }

        return $query->get()->total();
    }

    public function test_demo_seed_counts(): void
    {
        $this->markTestIncomplete('Task 12');

        $this->bootStarter();

        $this->assertSame(7, $this->total(Company::class));
        $this->assertSame(40, $this->total(Job::class));
        $this->assertSame(34, $this->total(Job::class, ['status' => 'published']));
        $this->assertSame(1, $this->total(User::class));
    }

    public function test_the_drafts_and_the_places_are_as_planned(): void
    {
        $this->markTestIncomplete('Task 12');

        $this->bootStarter();

        $northwind = $this->data()->query(Company::class)->where('name', 'Northwind Labs')->first();
        $this->assertNotNull($northwind);
        $this->assertSame(4, $this->total(Job::class, ['status' => 'draft', 'company' => $northwind->uuid]));
        $this->assertSame(6, $this->total(Job::class, ['status' => 'draft']));

        foreach (['Berlin', 'Zurich', 'Vienna', 'Amsterdam', 'Lisbon', 'London'] as $place) {
            $this->assertGreaterThan(0, $this->total(Job::class, ['place' => $place, 'status' => 'published']), $place);
        }
        foreach ($this->data()->query(Job::class)->where('status', 'published')->get()->items() as $job) {
            $this->assertGreaterThanOrEqual('2026-09-01', $job->publishedAt);
            $this->assertLessThanOrEqual('2026-11-05 23:59:59', $job->publishedAt);
        }
    }

    public function test_every_filter_value_has_at_least_three_published_jobs(): void
    {
        $this->markTestIncomplete('Task 12');

        $this->bootStarter();

        foreach (['full_time', 'part_time', 'contract', 'internship'] as $type) {
            $this->assertGreaterThanOrEqual(3, $this->total(Job::class, ['status' => 'published', 'employmentType' => $type]), $type);
        }
        foreach (['no', 'hybrid', 'full'] as $remote) {
            $this->assertGreaterThanOrEqual(3, $this->total(Job::class, ['status' => 'published', 'remote' => $remote]), $remote);
        }
    }

    public function test_seed_twice_keeps_counts(): void
    {
        $this->bootStarter();

        $this->console('db:seed');

        $this->assertSame(7, $this->total(Company::class));
        $this->assertSame(40, $this->total(Job::class));
        $this->assertSame(1, $this->total(User::class));
    }

    public function test_the_demo_employer_can_log_in_with_the_printed_password(): void
    {
        $this->bootStarter();

        $user = $this->data()->query(User::class)->where('email', 'employer@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue(password_verify('demo-employer', $user->passwordHash));
        $this->assertSame('Northwind Labs', $this->data()->find(Company::class, $user->company)?->name);
    }

    public function test_demo_seed_refuses_without_debug(): void
    {
        $this->bootStarter(debug: false);

        $this->assertSame(0, $this->total(Job::class));

        ob_start();
        $this->container()->make(DemoSeeder::class)->run($this->data());
        $output = ob_get_clean();

        $this->assertStringContainsString('Demo data is only seeded while APP_DEBUG is on.', $output);
        $this->assertSame(0, $this->total(Job::class));
        $this->assertSame(0, $this->total(User::class));
    }
}
