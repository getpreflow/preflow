<?php

declare(strict_types=1);

namespace Tests\Site;

use App\Jobs\JobSearch;
use App\Jobs\JobWriter;
use App\Models\Company;
use App\Models\Job;
use App\Models\User;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Validation\ValidationException;
use Tests\Support\StarterApp;

final class JobWriterTest extends TestCase
{
    use StarterApp;

    private DataManager $data;
    private JobWriter $writer;
    private User $owner;

    protected function setUp(): void
    {
        $this->bootStarter();
        $this->data = $this->container()->get(DataManager::class);
        $this->writer = new JobWriter($this->data, new JobSearch($this->data));
        $this->owner = $this->data->query(User::class)->where('email', 'employer@example.com')->first();
    }

    protected function tearDown(): void
    {
        $this->removeStarter();
    }

    /** @return array<string, mixed> */
    private function input(array $override = []): array
    {
        return $override + [
            'title' => 'Platform Wrangler',
            'place' => 'Berlin',
            'employmentType' => 'full_time',
            'remote' => 'hybrid',
            'salaryFrom' => '60000',
            'salaryTo' => '75000',
            'description' => 'You build the services behind our job board, from the API down to the queries.',
        ];
    }

    public function test_create_sets_owner_company_slug_and_draft(): void
    {
        $this->markTestIncomplete('Task 12');

        $job = $this->writer->create($this->input(), $this->owner);

        $this->assertSame(32, strlen($job->uuid));
        $this->assertSame($this->owner->company, $job->company);
        $this->assertSame('platform-wrangler', $job->slug);
        $this->assertSame('draft', $job->status);
        $this->assertNull($job->publishedAt);
        $this->assertNotNull($job->createdAt);
        $this->assertSame($job->createdAt, $job->updatedAt);
        $this->assertSame(60000, $job->salaryFrom);
        $this->assertNotNull($this->data->find(Job::class, $job->uuid));
    }

    public function test_create_ignores_non_editable_input(): void
    {
        $this->markTestIncomplete('Task 12');

        $other = $this->data->query(Company::class)->where('name', '!=', 'Northwind Labs')->first();

        $job = $this->writer->create($this->input([
            'company' => $other->uuid,
            'status' => 'published',
            'slug' => 'hacked',
            'uuid' => 'x',
        ]), $this->owner);

        $this->assertSame($this->owner->company, $job->company);
        $this->assertSame('draft', $job->status);
        $this->assertSame('platform-wrangler', $job->slug);
        $this->assertNotSame('x', $job->uuid);
    }

    public function test_invalid_input_throws_validation_exception_with_fields(): void
    {
        $this->markTestIncomplete('Task 12');

        try {
            $this->writer->create($this->input(['title' => '', 'salaryFrom' => '80000', 'salaryTo' => '50000']), $this->owner);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('title', $e->errors());
            $this->assertSame(['The salary range must not end below its start.'], $e->errors()['salaryTo']);
        }
    }

    public function test_a_salary_that_is_not_a_whole_number_gets_a_readable_message(): void
    {
        try {
            $this->writer->create($this->input(['salaryFrom' => 'lots', 'salaryTo' => '12.5']), $this->owner);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame(['The salary from must be a whole number.'], $e->errors()['salaryFrom']);
            $this->assertSame(['The salary to must be a whole number.'], $e->errors()['salaryTo']);
        }
    }

    public function test_publish_sets_published_at_once(): void
    {
        $this->markTestIncomplete('Task 12');

        $job = $this->writer->create($this->input(), $this->owner);

        $job = $this->writer->publish($job);
        $this->assertSame('published', $job->status);
        $first = $job->publishedAt;
        $this->assertNotNull($first);

        $job = $this->writer->unpublish($job);
        $this->assertSame('draft', $job->status);
        $this->assertSame($first, $job->publishedAt);

        $job = $this->writer->publish($job);
        $this->assertSame($first, $job->publishedAt);
        $this->assertSame('published', $this->data->find(Job::class, $job->uuid)->status);
    }

    public function test_update_is_partial_and_keeps_the_slug(): void
    {
        $this->markTestIncomplete('Task 12');

        $job = $this->writer->create($this->input(), $this->owner);

        $job = $this->writer->update($job, ['title' => 'Senior Platform Wrangler']);

        $this->assertSame('Senior Platform Wrangler', $job->title);
        $this->assertSame('platform-wrangler', $job->slug);
        $this->assertSame('Berlin', $job->place);
        $this->assertSame('Senior Platform Wrangler', $this->data->find(Job::class, $job->uuid)->title);
    }

    public function test_delete_removes_the_job(): void
    {
        $this->markTestIncomplete('Task 12');

        $job = $this->writer->create($this->input(), $this->owner);

        $this->writer->delete($job);

        $this->assertNull($this->data->find(Job::class, $job->uuid));
    }

    public function test_owned_by_refuses_other_company(): void
    {
        $this->markTestIncomplete('Task 12');

        $mine = $this->writer->create($this->input(), $this->owner);
        $foreign = $this->data->query(Job::class)->where('company', '!=', $this->owner->company)->first();

        $this->assertSame($mine->uuid, $this->writer->ownedBy($mine->uuid, $this->owner)?->uuid);
        $this->assertNull($this->writer->ownedBy($foreign->uuid, $this->owner));
        $this->assertNull($this->writer->ownedBy('missing', $this->owner));
    }

    public function test_slug_collision_gets_suffix(): void
    {
        $this->markTestIncomplete('Task 12');

        $first = $this->writer->create($this->input(), $this->owner);
        $second = $this->writer->create($this->input(), $this->owner);
        $third = $this->writer->create($this->input(['title' => 'Platform Wrangler']), $this->owner);

        $this->assertSame('platform-wrangler', $first->slug);
        $this->assertSame('platform-wrangler-2', $second->slug);
        $this->assertSame('platform-wrangler-3', $third->slug);
    }

    public function test_slug_is_ascii(): void
    {
        $this->markTestIncomplete('Task 12');

        $job = $this->writer->create($this->input(['title' => 'Köchin / Koch (m/w/d) – Zürich']), $this->owner);

        $this->assertSame('kochin-koch-m-w-d-zurich', $job->slug);
    }
}
