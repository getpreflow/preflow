<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Job;
use App\Models\User;
use Preflow\Data\DataManager;
use Preflow\Publishing\Publisher;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidationResult;

/**
 * Every change an employer makes to a job goes through here, so the rules live in one place:
 * which keys a form may set and who owns a job. What the public sees is Folio's business:
 * a save changes the working copy, and only publish() puts it on the site, as in Folio's admin.
 */
final class JobWriter
{
    /** Anything else in the input (company, status, slug...) is ignored: the form cannot set it. */
    private const array EDITABLE = ['title', 'place', 'employmentType', 'remote', 'salaryFrom', 'salaryTo', 'description'];

    public function __construct(
        private readonly DataManager $data,
        private readonly Publisher $publisher,
    ) {}

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function create(array $input, User $owner): Job
    {
        $job = new Job();
        $this->apply($job, $input);
        $job->uuid = bin2hex(random_bytes(16));
        $job->company = $owner->company;
        $job->createdAt = $job->updatedAt = self::now();
        // Made from the title once; editing the title later keeps the address people may have shared.
        $job->slug = Slugs::unique($this->data, Job::class, (string) $job->title);

        $this->data->save($job);

        return $job;
    }

    /**
     * @param array<string, mixed> $input only the keys present change
     * @throws ValidationException
     */
    public function update(Job $job, array $input): Job
    {
        $this->apply($job, $input);
        $job->updatedAt = self::now();
        $this->data->save($job);

        return $job;
    }

    /**
     * Puts the working copy on the site, as Folio's Publish button does. The revision names the
     * employer, so staff see in Folio who published it.
     *
     * @throws ValidationException when the working copy breaks a rule (staff may have saved a half-finished draft)
     */
    public function publish(Job $job, User $by): Job
    {
        if ($job->postedOn === null) {
            // The first publish dates the job. Later ones keep the date, so the job keeps its place.
            $job->postedOn = (new \DateTimeImmutable())->format('Y-m-d');
            $this->data->save($job);
        }
        $this->publisher->publish($this->data->findType('job', (string) $job->uuid), 'employer:' . $by->email);

        return $job;
    }

    /** Takes the job off the site; the working copy stays, as a draft. */
    public function unpublish(Job $job): Job
    {
        $this->publisher->unpublish('job', (string) $job->uuid);

        return $job;
    }

    public function delete(Job $job): void
    {
        $this->data->delete($job);
        // The published copy and the revisions are Folio's: without this the job would stay on the site.
        $this->publisher->forget('job', (string) $job->uuid);
    }

    /** draft (never published), published, or changed (published, with edits not yet published). */
    public function state(Job $job): string
    {
        return $this->publisher->state($this->data->findType('job', (string) $job->uuid))->value;
    }

    /**
     * state() for a list, with one query for all of them.
     *
     * @param list<Job> $jobs
     * @return array<string, string> keyed by uuid
     */
    public function states(array $jobs): array
    {
        $ids = array_map(static fn (Job $job): string => (string) $job->uuid, $jobs);

        return array_map(static fn ($state): string => $state->value, $this->publisher->states('job', $ids));
    }

    /** The job in any state, but only if it belongs to the user's company. */
    public function ownedBy(string $uuid, User $user): ?Job
    {
        $job = $this->data->find(Job::class, $uuid);

        return $job instanceof Job && $user->company !== null && $job->company === $user->company ? $job : null;
    }

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    private function apply(Job $job, array $input): void
    {
        $values = array_intersect_key($input, array_flip(self::EDITABLE));
        $errors = [];
        foreach ($values as $key => $value) {
            if (in_array($key, ['salaryFrom', 'salaryTo'], true)) {
                // A form sends '' for an empty salary field: that means "not given", not zero.
                if ($value === '' || $value === null) {
                    $values[$key] = null;
                } elseif (is_int($value) || (is_string($value) && preg_match('/^-?\d{1,9}$/', $value) === 1)) {
                    $values[$key] = (int) $value;
                } else {
                    // Not assignable to an int property: report it the way a rule would.
                    unset($values[$key]);
                    $errors[$key] = ['The ' . ($key === 'salaryFrom' ? 'salary from' : 'salary to') . ' must be a whole number.'];
                }
            } elseif (!is_string($value)) {
                $values[$key] = null; // the required rule then reports it
            }
        }
        if ($errors !== []) {
            throw new ValidationException(new ValidationResult($errors));
        }
        $job->fill($values);
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
