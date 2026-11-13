<?php

declare(strict_types=1);

namespace Preflow\Publishing\Tests\Fixtures;

use Preflow\Data\DynamicRecord;
use Preflow\Publishing\SaveHooks;
use Preflow\Publishing\WriteKind;

/** SaveHooks whose after() throws; the rest is the Recorder's. */
final class ThrowingAfter implements SaveHooks
{
    public function __construct(
        private readonly Recorder $recorder,
    ) {}

    public function before(DynamicRecord $record, WriteKind $kind = WriteKind::Save): void
    {
        $this->recorder->before($record, $kind);
    }

    public function after(DynamicRecord $record, WriteKind $kind = WriteKind::Save): void
    {
        throw new \RuntimeException('The after hook broke.');
    }

    public function beforeDelete(DynamicRecord $record): void {}

    public function afterDelete(DynamicRecord $record): void {}

    public function replacesSave(string $type): bool
    {
        return false;
    }

    public function save(DynamicRecord $record, ?DynamicRecord $previous): void {}
}
