<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

/**
 * Whether preflow/ai is installed. preflow/i18n and Folio only suggest it: machine translation,
 * AI drafts and the AI settings exist only when it is there. The one place that asks, so every
 * AI feature of these packages appears or disappears together (Folio asks here too, for its AI
 * screens, routes, nav items and permissions).
 *
 * @since 0.80.0
 */
final class AiPackage
{
    /** True when preflow/ai's classes can be loaded (the package is installed). */
    public static function installed(): bool
    {
        return class_exists(\Preflow\Ai\AiServiceProvider::class);
    }
}
