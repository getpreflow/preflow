<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

use Preflow\Ai\Ai;
use Preflow\Ai\AiUnavailable;

/**
 * The background side of queued translation jobs: a cron job calls work() every minute, which
 * steps queued and running jobs (oldest first, one record per step) until nothing moves any more
 * or the time budget is used. A job still marked running is continued, since its driver may have
 * crashed; the record lease keeps two drivers off the same record. Every run leaves a heartbeat
 * so an admin screen can tell whether the cron job runs at all, also when AI is off.
 */
final class TranslationWorker
{
    /** How many of the newest jobs are looked through for work. */
    private const JOBS = 200;

    public function __construct(
        private readonly JobRunner $runner,
        private readonly JobStore $jobs,
        private readonly Ai $ai,
        private readonly string $stateFile,
        private readonly ?\Closure $now = null,
    ) {}

    /**
     * @param int $seconds time budget; checked between steps, so a run can overshoot by one step
     * @param (\Closure(string): void)|null $log gets one line per record: "{collection} {id} {locales}: {status}"
     */
    public function work(int $seconds = 50, ?\Closure $log = null): WorkReport
    {
        $start = $this->now();
        $this->writeHeartbeat($start);

        if (!$this->ai->enabled()) {
            return new WorkReport(0, 0.0, false, null);
        }

        $deadline = $start->getTimestamp() + $seconds;
        $records = 0;
        $cost = 0.0;
        $unknown = false;

        try {
            do {
                $progress = false;
                foreach ($this->queue() as $job) {
                    // Depth first: the oldest job is finished before a younger one is started.
                    while ($this->now()->getTimestamp() < $deadline) {
                        $before = $job;
                        try {
                            $job = $this->runner->step($job->id);
                        } catch (\InvalidArgumentException) {
                            break; // the job was removed meanwhile
                        }
                        $cost += $job->costUsd - $before->costUsd;
                        $unknown = $unknown || $job->costUnknown;

                        $worked = array_values(array_diff($before->pending(), $job->pending()));
                        foreach ($worked as $id) {
                            $records++;
                            if ($log !== null) {
                                $log($job->collection . ' ' . $id . ' ' . implode(',', $job->locales) . ': ' . ($job->items[$id]['status'] ?? 'unknown'));
                            }
                        }
                        if ($worked === []) {
                            break;
                        }
                        $progress = true;
                        if ($job->state !== 'queued' && $job->state !== 'running') {
                            break;
                        }
                    }
                }
            } while ($progress && $this->now()->getTimestamp() < $deadline);
        } catch (AiUnavailable $e) {
            return new WorkReport($records, $cost, $unknown, $e->getMessage());
        }

        return new WorkReport($records, $cost, $unknown, null);
    }

    /** When work() last started, or null if it never ran. */
    public function lastRun(): ?\DateTimeImmutable
    {
        $raw = is_file($this->stateFile) ? @file_get_contents($this->stateFile) : false;
        $data = is_string($raw) ? json_decode($raw, true) : null;
        $at = is_array($data) ? ($data['last_run'] ?? null) : null;

        try {
            return is_string($at) ? new \DateTimeImmutable($at) : null;
        } catch (\Exception) {
            return null;
        }
    }

    /** @return list<Job> queued and running jobs, oldest first */
    private function queue(): array
    {
        $open = array_values(array_filter(
            array_reverse($this->jobs->recent(self::JOBS)),
            static fn (Job $job): bool => $job->state === 'queued' || $job->state === 'running',
        ));
        // Stable: jobs without a queue time (saved by 0.47) keep their place at the front.
        usort($open, static fn (Job $a, Job $b): int => ($a->queuedAt ?? '') <=> ($b->queuedAt ?? ''));

        return $open;
    }

    private function writeHeartbeat(\DateTimeImmutable $at): void
    {
        $dir = dirname($this->stateFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($this->stateFile, json_encode(['last_run' => $at->format(\DATE_ATOM)]), LOCK_EX);
    }

    private function now(): \DateTimeImmutable
    {
        return $this->now !== null ? ($this->now)() : new \DateTimeImmutable();
    }
}
