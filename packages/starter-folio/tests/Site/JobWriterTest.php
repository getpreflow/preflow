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
use Preflow\Publishing\Publisher;
use Preflow\Validation\ValidationException;
use Tests\Support\StarterApp;

final class JobWriterTest extends TestCase
{
    use StarterApp;

    private DataManager $data;
    private JobWriter $writer;
    private JobSearch $search;
    private Publisher $publisher;
    private User $owner;

    protected function setUp(): void
    {
        $this->bootStarter();
        $this->data = $this->container()->get(DataManager::class);
        $this->writer = $this->container()->get(JobWriter::class);
        $this->publisher = $this->container()->get(Publisher::class);
        $this->search = $this->container()->get(JobSearch::class);
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
        $job = $this->writer->create($this->input(), $this->owner);

        $this->assertSame(32, strlen($job->uuid));
        $this->assertSame($this->owner->company, $job->company);
        $this->assertSame('platform-wrangler', $job->slug);
        $this->assertSame('draft', $this->writer->state($job));
        $this->assertNull($this->publisher->snapshot('job', $job->uuid), 'nothing is public yet');
        $this->assertNotNull($job->createdAt);
        $this->assertSame($job->createdAt, $job->updatedAt);
        $this->assertSame(60000, $job->salaryFrom);
        $this->assertNotNull($this->data->find(Job::class, $job->uuid));
    }

    public function test_create_ignores_non_editable_input(): void
    {
        $other = $this->data->query(Company::class)->where('name', '!=', 'Northwind Labs')->first();

        $job = $this->writer->create($this->input([
            'company' => $other->uuid,
            'status' => 'published',
            'slug' => 'hacked',
            'uuid' => 'x',
        ]), $this->owner);

        $this->assertSame($this->owner->company, $job->company);
        $this->assertSame('draft', $this->writer->state($job));
        $this->assertSame('platform-wrangler', $job->slug);
        $this->assertNotSame('x', $job->uuid);
    }

    public function test_invalid_input_throws_validation_exception_with_fields(): void
    {
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

    public function test_publish_unpublish_and_edit_go_through_folio(): void
    {
        $job = $this->writer->create($this->input(), $this->owner);

        $this->writer->publish($job, $this->owner);
        $this->assertSame('published', $this->writer->state($job));
        $snapshot = $this->publisher->snapshot('job', $job->uuid);
        $this->assertSame('Platform Wrangler', $snapshot?->get('title'));
        $this->assertSame('employer:employer@example.com', $snapshot->get('_published_by'));
        $this->assertNotNull($this->search->byId($job->uuid)?->_published_at);

        // An edit changes the working copy only: the public sees the old title until the next publish.
        $job = $this->writer->update($job, ['title' => 'Senior Platform Wrangler']);
        $this->assertSame('changed', $this->writer->state($job));
        $this->assertSame('Platform Wrangler', $this->search->byId($job->uuid)?->title);
        $this->writer->publish($job, $this->owner);
        $this->assertSame('Senior Platform Wrangler', $this->search->byId($job->uuid)?->title);

        $this->writer->unpublish($job);
        $this->assertSame('draft', $this->writer->state($job));
        $this->assertNull($this->search->byId($job->uuid));
        $this->assertNotNull($this->data->find(Job::class, $job->uuid), 'unpublished, not deleted');

        $this->writer->publish($job, $this->owner);
        $this->assertSame('published', $this->writer->state($job));
    }

    public function test_republishing_keeps_the_posted_date_and_the_place_in_the_list(): void
    {
        $job = $this->writer->create($this->input(), $this->owner);
        $this->assertNull($job->postedOn);
        $this->writer->publish($job, $this->owner);
        $this->assertSame((new \DateTimeImmutable())->format('Y-m-d'), $this->data->find(Job::class, $job->uuid)->postedOn, 'set on the first publish');
        $this->assertSame($job->uuid, $this->search->latest(1)[0]->uuid);

        // Dated back (an editor can change "Posted on" in Folio), it sits among the older jobs.
        $job = $this->data->find(Job::class, $job->uuid);
        $job->postedOn = '2026-09-03';
        $this->data->save($job);
        $this->writer->publish($job, $this->owner);
        $place = fn (): int|false => array_search($job->uuid, array_map(static fn (Job $j): string => $j->uuid, $this->search->latest(50)), true);
        $before = $place();
        $this->assertGreaterThan(0, $before);

        // Unpublish and publish again: neither the date nor the place changes.
        $this->writer->unpublish($job);
        $this->writer->publish($job, $this->owner);
        $this->assertSame('2026-09-03', $this->search->byId($job->uuid)?->postedOn);
        $this->assertSame($before, $place());

        // Publishing an edit doesn't move it to the top either.
        $job = $this->writer->update($job, ['title' => 'Senior Platform Wrangler']);
        $this->writer->publish($job, $this->owner);
        $this->assertSame('Senior Platform Wrangler', $this->search->byId($job->uuid)?->title);
        $this->assertSame('2026-09-03', $this->search->byId($job->uuid)?->postedOn);
        $this->assertSame($before, $place());
    }

    public function test_update_is_partial_and_keeps_the_slug(): void
    {
        $job = $this->writer->create($this->input(), $this->owner);

        $job = $this->writer->update($job, ['title' => 'Senior Platform Wrangler']);

        $this->assertSame('Senior Platform Wrangler', $job->title);
        $this->assertSame('platform-wrangler', $job->slug);
        $this->assertSame('Berlin', $job->place);
        $this->assertSame('Senior Platform Wrangler', $this->data->find(Job::class, $job->uuid)->title);
    }

    public function test_an_edit_without_the_right_to_publish_cancels_a_scheduled_publish(): void
    {
        $job = $this->writer->create($this->input(), $this->owner);
        $record = $this->data->findType('job', $job->uuid);
        $record->set('_publish_at', '2099-01-01T09:00');
        $this->data->saveType($record, false);

        $this->writer->update($job, ['title' => 'Kept by staff'], mayPublish: true);
        $this->assertSame('2099-01-01T09:00', $this->data->findType('job', $job->uuid)->get('_publish_at'));

        $this->writer->update($job, ['title' => 'Changed by a draft-only account'], mayPublish: false);
        $this->assertSame('', (string) $this->data->findType('job', $job->uuid)->get('_publish_at'));
    }

    public function test_delete_removes_the_job(): void
    {
        $job = $this->writer->create($this->input(), $this->owner);
        $this->writer->publish($job, $this->owner);

        $this->writer->delete($job);

        $this->assertNull($this->data->find(Job::class, $job->uuid));
        // Folio forgets it too: no published copy left on the site, no revisions left behind.
        $this->assertNull($this->publisher->snapshot('job', $job->uuid));
        $this->assertSame([], $this->publisher->revisions('job', $job->uuid));
    }

    public function test_owned_by_refuses_other_company(): void
    {
        $mine = $this->writer->create($this->input(), $this->owner);
        $foreign = $this->data->query(Job::class)->where('company', '!=', $this->owner->company)->first();

        $this->assertSame($mine->uuid, $this->writer->ownedBy($mine->uuid, $this->owner)?->uuid);
        $this->assertNull($this->writer->ownedBy($foreign->uuid, $this->owner));
        $this->assertNull($this->writer->ownedBy('missing', $this->owner));
    }

    public function test_slug_collision_gets_suffix(): void
    {
        $first = $this->writer->create($this->input(), $this->owner);
        $second = $this->writer->create($this->input(), $this->owner);
        $third = $this->writer->create($this->input(['title' => 'Platform Wrangler']), $this->owner);

        $this->assertSame('platform-wrangler', $first->slug);
        $this->assertSame('platform-wrangler-2', $second->slug);
        $this->assertSame('platform-wrangler-3', $third->slug);
    }

    public function test_slug_is_ascii(): void
    {
        $job = $this->writer->create($this->input(['title' => 'Köchin / Koch (m/w/d) – Zürich']), $this->owner);

        $this->assertSame('kochin-koch-m-w-d-zurich', $job->slug);
    }
}
