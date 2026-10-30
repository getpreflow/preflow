<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

interface JobStore
{
    public function save(Job $job): void;

    public function get(string $id): ?Job;

    /**
     * Reads the job, lets $change modify it and saves it, all under an exclusive lock on that job, so
     * two drivers (a browser and the cron worker) never write back each other's older copy. $change
     * must be quick and must not call the store. Returns the saved job, or null for an unknown id.
     *
     * @param \Closure(Job): void $change
     */
    public function update(string $id, \Closure $change): ?Job;

    /**
     * The most recently saved jobs, newest first, at most $limit, so a screen can offer to
     * continue a run whose page was closed.
     *
     * @return list<Job>
     */
    public function recent(int $limit): array;
}
