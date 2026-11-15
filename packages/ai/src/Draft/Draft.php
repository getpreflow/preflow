<?php

declare(strict_types=1);

namespace Preflow\Ai\Draft;

/**
 * A written draft: its HTML (as the writer answered it: sanitise before showing it), what it cost
 * in USD (null when unknown, never 0 for "don't know"), and who wrote it.
 *
 * @since 0.77
 */
final readonly class Draft
{
    public function __construct(
        public string $html,
        public ?float $costUsd,
        public string $provider,
        public string $model,
    ) {}
}
