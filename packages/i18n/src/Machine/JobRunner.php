<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

use Preflow\Ai\AiUnavailable;

/**
 * Runs a translation job one item at a time, so a request or cron tick stays short and a failure
 * costs one item. Texts are written only for units that came back Translated; a failed unit leaves
 * the stored text alone. The job's cost is the sum of what its own requests cost, taken from the
 * translator (see ReportsCost); a request without a price marks it unknown, and a translator that
 * does not report leaves it at 0.
 */
final class JobRunner
{
    public function __construct(
        private readonly TextSources $sources,
        private readonly MachineTranslator $translator,
        private readonly Estimator $estimator,
        private readonly JobStore $store,
        private readonly RecordLease $lease,
        private readonly ?\Closure $now = null,
    ) {}

    /**
     * Works out what the run would cost and lists its items. Reads units, writes nothing to the source.
     *
     * @param list<string> $locales
     * @param list<string>|null $ids null = whole collection
     * @param string|null $prefix only the texts at this path or below it (see unitsOf)
     */
    public function plan(string $collection, array $locales, Scope $scope, ?array $ids = null, ?string $note = null, ?string $prefix = null): Job
    {
        $source = $this->sources->get($collection);
        $ids = $ids === null ? $source->items() : array_values($ids);
        $locales = array_values($locales);

        $units = [];
        foreach ($ids as $id) {
            foreach ($locales as $locale) {
                array_push($units, ...self::withinPrefix($source->units($id, $locale, $scope), $prefix));
            }
        }

        $job = new Job(bin2hex(random_bytes(8)), $collection, $locales, $scope, $note, $this->estimator->estimate($units), prefix: $prefix === '' ? null : $prefix);
        foreach ($ids as $id) {
            $job->items[$id] = ['status' => 'pending', 'translated' => 0, 'failed' => 0, 'warnings' => 0, 'message' => null, 'draft_changes' => false];
        }
        $this->store->save($job);

        return $job;
    }

    /** How many of the newest jobs unfinished() looks through. */
    private const RECENT_JOBS = 50;

    /**
     * Puts a planned or stopped job in the queue (clearing why it stopped), and takes up a job still
     * marked running whose page was closed or whose step never came back; a finished job is
     * returned as it is. Items done before stay done: the run continues with the pending ones.
     * Starting never works on the job itself: a browser or the cron worker calls step() for that.
     * Confirming that the texts may go live sticks to the job; starting again cannot take it back.
     */
    public function start(string $jobId, bool $confirmLive = false, ?string $by = null): Job
    {
        $job = $this->load($jobId);
        if ($job->state === 'planned' || $job->state === 'stopped' || $job->state === 'running') {
            $job->state = 'queued';
            $job->message = null;
            $job->queuedAt = ($this->now !== null ? ($this->now)() : new \DateTimeImmutable())->format(\DATE_ATOM);
            $job->queuedBy = $by ?? $job->queuedBy;
            $job->confirmedLive = $job->confirmedLive || $confirmLive;
            $this->store->save($job);
        }

        return $job;
    }

    /**
     * Processes the next pending items, skipping records another worker holds (they stay pending
     * for a later step) and releasing each claim when its item is through. Does nothing for a
     * stopped or finished job; a job writing live content without confirmation is stopped before
     * anything is written. The job is "running" while a step works and "queued" again after it,
     * until nothing is pending. An error
     * other than AiUnavailable fails only the item it happened on, so one bad record never
     * blocks the run. A stop saved while an item is being processed wins over this step.
     *
     * @throws AiUnavailable after the job has been stopped and saved; items done so far stay done
     */
    public function step(string $jobId, int $maxItems = 1): Job
    {
        $job = $this->load($jobId);
        if ($job->state === 'stopped' || $job->state === 'done') {
            return $job;
        }

        $source = $this->sources->get($job->collection);
        if ($source->writesLive() && !$job->confirmedLive) {
            $job->state = 'stopped';
            $job->message = 'Confirm that the translations go live before starting.';
            $this->store->save($job);

            return $job;
        }

        $job->state = 'running';
        if ($this->translator instanceof ReportsCost) {
            $this->translator->reportCostTo(static function (?float $usd) use ($job): void {
                if ($usd === null) {
                    $job->costUnknown = true;
                } else {
                    $job->costUsd += $usd;
                }
            });
        }

        try {
            $taken = 0;
            foreach ($job->pending() as $id) {
                if ($taken >= max(1, $maxItems)) {
                    break;
                }
                if (!$this->lease->claim($job->collection, $id)) {
                    continue;
                }
                $taken++;
                try {
                    $job->items[$id] = $this->processItem($source, $job, $id);
                } catch (AiUnavailable $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    $job->items[$id] = ['status' => 'failed', 'message' => $e->getMessage()] + $job->items[$id];
                } finally {
                    $this->lease->release($job->collection, $id);
                }
                if ($this->store->get($jobId)?->state === 'stopped') {
                    // Stopped (from another request) while this item was in hand: keep the item, then stop.
                    $job->state = $job->pending() === [] ? 'done' : 'stopped';
                    $this->store->save($job);

                    return $job;
                }
                $this->store->save($job);
            }
            $job->state = $job->pending() === [] ? 'done' : 'queued';
            $this->store->save($job);
        } catch (AiUnavailable $e) {
            $job->state = 'stopped';
            $job->message = $e->getMessage();
            $this->store->save($job);
            throw $e;
        } finally {
            if ($this->translator instanceof ReportsCost) {
                $this->translator->reportCostTo(null);
            }
        }

        return $job;
    }

    /**
     * The collection's jobs that are not done (planned, running or stopped), newest first, so a
     * run whose page was closed can be continued. Looks through the RECENT_JOBS newest jobs.
     *
     * @return list<Job>
     */
    public function unfinished(string $collection, int $max = 5): array
    {
        $jobs = array_filter(
            $this->store->recent(self::RECENT_JOBS),
            static fn (Job $job): bool => $job->collection === $collection && $job->state !== 'done',
        );

        return array_slice(array_values($jobs), 0, $max);
    }

    /**
     * The queued or running job that will still translate $id into $locale under $prefix, if any.
     * Looks through the RECENT_JOBS newest jobs.
     */
    public function pendingFor(string $collection, string $id, string $locale, ?string $prefix = null): ?Job
    {
        $prefix = $prefix === '' ? null : $prefix;
        foreach ($this->store->recent(self::RECENT_JOBS) as $job) {
            if ($job->collection === $collection
                && ($job->state === 'queued' || $job->state === 'running')
                && in_array($id, $job->pending(), true)
                && in_array($locale, $job->locales, true)
                && $job->prefix === $prefix) {
                return $job;
            }
        }

        return null;
    }

    /**
     * Jobs that are queued, running, or stopped with a reason (so someone sees why), newest first.
     *
     * @return list<Job>
     */
    public function active(int $max = 10): array
    {
        $jobs = array_filter(
            $this->store->recent(self::RECENT_JOBS),
            static fn (Job $job): bool => $job->state === 'queued' || $job->state === 'running' || ($job->state === 'stopped' && $job->message !== null),
        );

        return array_slice(array_values($jobs), 0, $max);
    }

    public function stop(string $jobId): Job
    {
        $job = $this->load($jobId);
        if ($job->state !== 'done') {
            $job->state = 'stopped';
            $this->store->save($job);
        }

        return $job;
    }

    private function load(string $jobId): Job
    {
        return $this->store->get($jobId) ?? throw new \InvalidArgumentException("Unknown job \"{$jobId}\".");
    }

    /** @return array{status: string, translated: int, failed: int, warnings: int, message: ?string, draft_changes: bool} */
    private function processItem(TextSource $source, Job $job, string $id): array
    {
        $item = ['status' => 'done', 'translated' => 0, 'failed' => 0, 'warnings' => 0, 'message' => null, 'draft_changes' => false];
        $hadUnits = false;
        $conflict = null;
        $skipped = null;

        foreach ($job->locales as $locale) {
            $units = self::withinPrefix($source->units($id, $locale, $job->scope), $job->prefix);
            if ($units === []) {
                continue;
            }
            $hadUnits = true;

            $texts = [];
            $firstError = null;
            foreach ($this->translator->translateUnits($units, $job->note) as $result) {
                $item['warnings'] += count($result->warnings);
                if ($result->status === UnitStatus::Translated && $result->text !== null) {
                    $texts[$result->id] = $result->text;
                } else {
                    $item['failed']++;
                    $firstError ??= $result->error;
                }
            }
            $item['message'] ??= $firstError;
            if ($texts === []) {
                continue;
            }

            $outcome = $source->write($id, $locale, $texts);
            $item['draft_changes'] = $item['draft_changes'] || $outcome->hadDraftChanges;
            if ($outcome->status === WriteOutcomeStatus::Written && $outcome->leftAlone !== []) {
                // Texts someone changed while the model worked keep that person's version.
                $item['message'] ??= $outcome->message;
            }
            match ($outcome->status) {
                WriteOutcomeStatus::Written => $item['translated'] += count(array_diff_key($texts, array_flip($outcome->leftAlone))),
                WriteOutcomeStatus::Conflict => $conflict ??= $outcome->message ?? 'changed meanwhile',
                WriteOutcomeStatus::Skipped => $skipped ??= $outcome->message ?? 'skipped by the source',
            };
        }

        if (!$hadUnits) {
            return ['status' => 'skipped', 'message' => 'nothing to translate'] + $item;
        }
        if ($conflict !== null) {
            return ['status' => 'conflict', 'message' => $conflict] + $item;
        }
        if ($item['failed'] > 0) {
            return ['status' => 'failed', 'message' => $item['message'] ?? 'some texts could not be translated'] + $item;
        }
        if ($item['translated'] === 0 && $skipped !== null) {
            return ['status' => 'skipped', 'message' => $skipped] + $item;
        }

        return $item;
    }

    /**
     * Keeps the units at $prefix or below it ("flow" keeps "flow" and "flow.a", not "flowers");
     * the same rule as the Folio suggest endpoint. No prefix keeps everything.
     *
     * @param list<TranslationUnit> $units
     * @return list<TranslationUnit>
     */
    private static function withinPrefix(array $units, ?string $prefix): array
    {
        if ($prefix === null || $prefix === '') {
            return $units;
        }

        return array_values(array_filter(
            $units,
            static fn (TranslationUnit $u): bool => $u->id === $prefix || str_starts_with($u->id, $prefix . '.'),
        ));
    }
}
