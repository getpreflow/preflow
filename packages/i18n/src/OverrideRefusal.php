<?php

declare(strict_types=1);

namespace Preflow\I18n;

/** Why an override was refused: a reason (one of OverrideRules' constants) and what to tell about it. */
final readonly class OverrideRefusal
{
    /** @param array<string, string|int> $params e.g. ['max' => 2000] or ['placeholder' => ':link'] */
    public function __construct(
        public string $reason,
        public array $params = [],
    ) {}
}
