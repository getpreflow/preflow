<?php

declare(strict_types=1);

namespace Preflow\Ai;

final readonly class TokenUsage
{
    public function __construct(public int $input = 0, public int $output = 0, public int $cachedInput = 0) {}
}
