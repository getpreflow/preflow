<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

/** What a run would send and cost, worked out before anything is sent. */
final readonly class Estimate
{
    public function __construct(
        public int $units,
        /** Characters of source text. */
        public int $characters,
        public int $inputTokens,
        public int $outputTokens,
        /** Null when the configured model has no price: an unknown cost is not shown as free. */
        public ?float $costUsd,
        public int $requests,
    ) {}
}
