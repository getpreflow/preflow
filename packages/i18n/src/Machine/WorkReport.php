<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

/** What one TranslationWorker run did. */
final readonly class WorkReport
{
    public function __construct(
        /** Records worked through in this run. */
        public int $records,
        /** USD the jobs' requests cost during this run; a lower bound when $costUnknown. */
        public float $costUsd,
        /** A job worked on has requests without a price. */
        public bool $costUnknown,
        /** Why the run ended early because AI could not be used; null for a normal end. */
        public ?string $stoppedBecause,
    ) {}
}
