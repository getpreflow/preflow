<?php

declare(strict_types=1);

namespace Preflow\Folio\Ai;

/**
 * What an AI request cost, as Folio shows it: "$0.12", "< $0.01" for a small amount above zero,
 * null when the model has no price (shown as unknown, never as $0.00).
 *
 * @since 0.77 (before, AiTranslateController::cost())
 */
final class CostFormat
{
    public static function usd(?float $usd): ?string
    {
        if ($usd === null) {
            return null;
        }

        return $usd > 0 && $usd < 0.01 ? '< $0.01' : '$' . number_format($usd, 2);
    }
}
