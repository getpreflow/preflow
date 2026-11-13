<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Job;
use App\Models\User;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Slugs;
use Preflow\Publishing\PublishFailed;
use Preflow\Publishing\Publisher;
use Preflow\Publishing\RecordDeleter;
use Preflow\Publishing\RecordWriter;
use Preflow\Publishing\SaveOptions;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidationResult;

/**
 * Every change an employer makes to a job goes through here, so the rules live in one place:
 * which keys a form may set and who owns a job. Records are written through the RecordWriter, so
 * the hooks and rules run as in the admin. What the public sees: a save changes the working copy,
 * and only publish() puts it on the site.
 */
final class JobWriter
{
    /** Anything else in the input (company, status, slug...) is ignored: the form cannot set it. */
    private const array EDITABLE = ['title', 'place', 'employmentType', 'remote', 'salaryFrom', 'salaryTo', 'description'];

    public function __construct(
        private readonly DataManager $data,
        private readonly Publisher $publisher,
        private readonly RecordWriter $writer,
        private readonly RecordDeleter $deleter,
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

        $this->writer->create($this->record($job));

        return $job;
    }

    /**
     * @param array<string, mixed> $input only the keys present change
     * @param bool $mayPublish false for an account without job.publish: its save cancels a
     *                         publish staff scheduled in Folio, as a save in the admin does
     * @throws ValidationException
     */
    public function update(Job $job, array $input, bool $mayPublish = true): Job
    {
        $this->apply($job, $input);
        $job->updatedAt = self::now();
        $this->writer->save($this->record($job), new SaveOptions(mayPublish: $mayPublish));

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
        $record = $this->data->findType('job', (string) $job->uuid) ?? $this->record($job);
        if ($job->postedOn === null) {
            // The first publish dates the job. Later ones keep the date, so the job keeps its place.
            $job->postedOn = (new \DateTimeImmutable())->format('Y-m-d');
            $record->set('postedOn', $job->postedOn);
        }
        try {
            $this->writer->save($record, new SaveOptions(by: 'employer:' . $by->email, publish: true));
        } catch (PublishFailed $e) {
            throw $e->cause(); // the draft is stored; the caller shows what to fix
        }

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
        // Also the published copy and the revisions: without them the job would stay on the site.
        $this->deleter->delete('job', [(string) $job->uuid]);
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

    /**
     * The job's stored record with the model's values laid over it (a new one when it has none
     * yet), so the columns the app does not own (version, schedule, translations) stay. The
     * draft save skips the rules, so they run here: the employer gets the errors with the form.
     *
     * @throws ValidationException
     */
    private function record(Job $job): DynamicRecord
    {
        $def = $this->data->getTypeRegistry()->get('job');
        $record = $this->data->findType('job', (string) $job->uuid) ?? new DynamicRecord($def, []);
        $values = $job->toArray();
        foreach ([$def->idField, ...array_keys($def->fields)] as $name) {
            if (array_key_exists($name, $values)) {
                $record->set($name, $values[$name]);
            }
        }
        $this->data->validate($record, $job->rules());

        return $record;
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
