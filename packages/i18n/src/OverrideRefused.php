<?php

declare(strict_types=1);

namespace Preflow\I18n;

/** An override a writer handed over breaks the rules (OverrideRules::check()). */
final class OverrideRefused extends \InvalidArgumentException
{
    public function __construct(public readonly OverrideRefusal $refusal, string $key)
    {
        parent::__construct("Override for {$key} refused: {$refusal->reason}");
    }
}
