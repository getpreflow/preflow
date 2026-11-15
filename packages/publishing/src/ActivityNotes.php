<?php

declare(strict_types=1);

namespace Preflow\Publishing;

use Preflow\Core\Activity\Activity;
use Preflow\Core\Activity\ActivityKind;
use Preflow\Core\Activity\ActivityLog;
use Preflow\Data\DynamicRecord;
use Preflow\Data\RecordTitle;
use Psr\Log\LoggerInterface;

/**
 * How the writers (RecordWriter, RecordDeleter, Publisher) tell the activity log: the record's
 * type and id, its title now (RecordTitle, default language), what happened, the changed fields
 * and who. A log that fails is caught and logged as a warning: a write never fails because of it.
 *
 * @internal
 * @since 0.77
 */
final class ActivityNotes
{
    public function __construct(
        private readonly ?ActivityLog $log = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /** Whether there is a log to tell (a writer skips work only the log needs). */
    public function on(): bool
    {
        return $this->log !== null && !$this->log instanceof \Preflow\Core\Activity\NullActivityLog;
    }

    /** Erasure: the log forgets the record (ActivityLog::forget()); a failing log is logged. */
    public function forget(string $type, string $id): void
    {
        try {
            $this->log?->forget($type, $id);
        } catch (\Throwable $e) {
            $this->logger?->warning("Activity log: {$type} {$id} not forgotten: {$e->getMessage()}", ['exception' => $e]);
        }
    }

    /** @param list<string> $changed */
    public function note(DynamicRecord $record, ActivityKind $kind, array $changed = [], ?string $by = null): void
    {
        if ($this->log === null) {
            return;
        }
        $type = $record->getType()->key;
        $id = (string) $record->getId();
        try {
            $this->log->record(new Activity($type, $id, RecordTitle::of($record), $kind, $changed, $by));
        } catch (\Throwable $e) {
            $this->logger?->warning("Activity log: \"{$kind->value}\" of {$type} {$id} not recorded: {$e->getMessage()}", ['exception' => $e]);
        }
    }
}
