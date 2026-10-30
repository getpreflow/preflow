<?php

declare(strict_types=1);

namespace Preflow\Ai\Usage;

use Preflow\Ai\TokenUsage;

/**
 * One AI call as the usage log remembers it: who asked (purpose), whom, how many tokens, what it
 * cost. Deliberately no prompt or response text: the log is for budgeting, and content (which
 * may be personal) has no business sitting in a cost file. $costUsd is null for a model the
 * price table doesn't know, so an unknown price never looks like a free call.
 */
final readonly class UsageEntry
{
    public function __construct(
        public \DateTimeImmutable $at,
        public string $purpose,
        public string $provider,
        public string $model,
        public TokenUsage $usage,
        public ?float $costUsd,
        public bool $failed = false,
    ) {}
}
