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
    ) {}

    /**
     * Works out what the run would cost and lists its items. Reads units, writes nothing to the source.
     *
     * @param list<string> $locales
     * @param list<string>|null $ids null = whole collection
     */
    public function plan(string $collection, array $locales, Scope $scope, ?array $ids = null, ?string $note = null): Job
    {
        $source = $this->sources->get($collection);
        $ids = $ids === null ? $source->items() : array_values($ids);
        $locales = array_values($locales);

        $units = [];
        foreach ($ids as $id) {
            foreach ($locales as $locale) {
                array_push($units, ...$source->units($id, $locale, $scope));
            }
        }

        $job = new Job(bin2hex(random_bytes(8)), $collection, $locales, $scope, $note, $this->estimator->estimate($units));
        foreach ($ids as $id) {
            $job->items[$id] = ['status' => 'pending', 'translated' => 0, 'failed' => 0, 'warnings' => 0, 'message' => null, 'draft_changes' => false];
        }
        $this->store->save($job);

        return $job;
    }

    /**
     * Sets a planned or stopped job running again (clearing why it stopped); a finished job is
     * returned as it is. Items done before a stop stay done: the run continues with the pending ones.
     */
    public function start(string $jobId): Job
    {
        $job = $this->load($jobId);
        if ($job->state === 'planned' || $job->state === 'stopped') {
            $job->state = 'running';
            $job->message = null;
            $this->store->save($job);
        }

        return $job;
    }

    /**
     * Processes the next pending items. Does nothing for a stopped or finished job. An error
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

        $job->state = 'running';
        $source = $this->sources->get($job->collection);
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
            foreach (array_slice($job->pending(), 0, max(1, $maxItems)) as $id) {
                try {
                    $job->items[$id] = $this->processItem($source, $job, $id);
                } catch (AiUnavailable $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    $job->items[$id] = ['status' => 'failed', 'message' => $e->getMessage()] + $job->items[$id];
                }
                if ($this->store->get($jobId)?->state === 'stopped') {
                    // Stopped (from another request) while this item was in hand: keep the item, then stop.
                    $job->state = $job->pending() === [] ? 'done' : 'stopped';
                    $this->store->save($job);

                    return $job;
                }
                $this->store->save($job);
            }
            if ($job->pending() === []) {
                $job->state = 'done';
            }
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
            $units = $source->units($id, $locale, $job->scope);
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
}
