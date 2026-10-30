<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

interface JobStore
{
    public function save(Job $job): void;

    public function get(string $id): ?Job;

    /**
     * The most recently saved jobs, newest first, at most $limit, so a screen can offer to
     * continue a run whose page was closed.
     *
     * @return list<Job>
     */
    public function recent(int $limit): array;
}
