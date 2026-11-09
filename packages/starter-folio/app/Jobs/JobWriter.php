<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Job;
use App\Models\User;
use Preflow\Data\DataManager;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidationResult;

/**
 * Every change an employer makes to a job goes through here, so the rules live in one place:
 * which keys a form may set, who owns a job, and when it was published.
 */
final class JobWriter
{
    /** Anything else in the input (company, status, slug...) is ignored: the form cannot set it. */
    private const array EDITABLE = ['title', 'place', 'employmentType', 'remote', 'salaryFrom', 'salaryTo', 'description'];

    public function __construct(
        private readonly DataManager $data,
        private readonly JobSearch $search,
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
        $job->status = 'draft';
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

    public function publish(Job $job): Job
    {
        $job->status = 'published';
        // Set once: unpublishing and publishing again must not push the job to the top of the list.
        $job->publishedAt ??= self::now();
        $job->updatedAt = self::now();
        $this->data->save($job);

        return $job;
    }

    public function unpublish(Job $job): Job
    {
        $job->status = 'draft';
        $job->updatedAt = self::now();
        $this->data->save($job);

        return $job;
    }

    public function delete(Job $job): void
    {
        $this->data->delete($job);
    }

    /** The job in any status, but only if it belongs to the user's company. */
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
