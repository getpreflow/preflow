<?php

declare(strict_types=1);

namespace Preflow\Publishing\Tests\Fixtures;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Publishing\RecordAddresses;
use Preflow\Publishing\RecordChecks;
use Preflow\Publishing\RecordIndex;
use Preflow\Publishing\RecordTypes;
use Preflow\Publishing\RemovalTarget;
use Preflow\Publishing\SaveHooks;
use Preflow\Publishing\WriteKind;

/**
 * Every interface RecordWriter and RecordDeleter ask, recording each call in one log
 * ("step:type:id"), so a test sees the order of the steps. The $fail* closures, when set, run
 * inside a step (to throw).
 */
final class Recorder implements SaveHooks, RecordChecks, RecordIndex, RecordAddresses, RecordTypes
{
    /** @var list<string> */
    public array $log = [];

    /** @var list<string> the kind of write each before() and after() got ("step:type:id:kind") */
    public array $kinds = [];

    /** @var list<string> types kept in a manual order */
    public array $orderable = [];

    /** @var list<string> types whose records save() stores */
    public array $replaces = [];

    /** @var list<array{string, ?string}> what save() got: [the record's id, the previous record's id] */
    public array $saved = [];

    /** What sync() answers: an address left the site. */
    public bool $left = false;

    /** What finalTarget() answers; null: the target it got. */
    public ?RemovalTarget $final = null;

    /** @var list<?RemovalTarget> the targets removed() got */
    public array $targets = [];

    public ?\Closure $failBefore = null;
    public ?\Closure $failChecks = null;
    public ?\Closure $failBeforeDelete = null;

    public function __construct(
        private readonly ?DataManager $dm = null,
    ) {}

    public function before(DynamicRecord $record, WriteKind $kind = WriteKind::Save): void
    {
        $this->record('before', $record);
        $this->kinds[] = 'before:' . $record->getType()->key . ':' . $record->getId() . ':' . $kind->value;
        if ($this->failBefore !== null) {
            ($this->failBefore)($record);
        }
    }

    public function after(DynamicRecord $record, WriteKind $kind = WriteKind::Save): void
    {
        $this->record('after', $record);
        $this->kinds[] = 'after:' . $record->getType()->key . ':' . $record->getId() . ':' . $kind->value;
    }

    public function beforeDelete(DynamicRecord $record): void
    {
        $this->record('beforeDelete', $record);
        if ($this->failBeforeDelete !== null) {
            ($this->failBeforeDelete)($record);
        }
    }

    public function afterDelete(DynamicRecord $record): void
    {
        $this->record('afterDelete', $record);
    }

    public function replacesSave(string $type): bool
    {
        return in_array($type, $this->replaces, true);
    }

    /** Stores the record under the storage itself, so a later save sees it as previous. */
    public function save(DynamicRecord $record, ?DynamicRecord $previous): void
    {
        $this->record('save', $record);
        $this->saved[] = [(string) $record->getId(), $previous === null ? null : (string) $previous->getId()];
        $this->dm?->saveType($record, false);
    }

    public function validate(DynamicRecord $record): void
    {
        $this->record('checks', $record);
        if ($this->failChecks !== null) {
            ($this->failChecks)($record);
        }
    }

    public function refresh(DynamicRecord $record): void
    {
        $this->record('refresh', $record);
    }

    public function forget(string $type, string $id): void
    {
        $this->log[] = "forget:{$type}:{$id}";
    }

    public function sync(string $type, string $id, ?DynamicRecord $previousLive): bool
    {
        $this->log[] = "sync:{$type}:{$id}";

        return $this->left;
    }

    public function removed(string $type, string $id, ?RemovalTarget $target): void
    {
        $this->log[] = "removed:{$type}:{$id}";
        $this->targets[] = $target;
    }

    public function changed(\Closure $before, ?DynamicRecord $after): void
    {
        $was = $before();
        $this->log[] = 'changed:' . ($was === null ? '-' : $was->getType()->key . ':' . $was->getId()) . '>' . ($after === null ? '-' : $after->getType()->key . ':' . $after->getId());
    }

    public function finalTarget(?RemovalTarget $target, array $deleting): ?RemovalTarget
    {
        $this->log[] = 'finalTarget:' . implode(',', array_map(static fn (string $type, array $ids): string => $type . '=' . implode('+', $ids), array_keys($deleting), $deleting));

        return $this->final ?? $target;
    }

    public function orderable(string $type): bool
    {
        return in_array($type, $this->orderable, true);
    }

    private function record(string $step, DynamicRecord $record): void
    {
        $this->log[] = $step . ':' . $record->getType()->key . ':' . $record->getId();
    }
}
