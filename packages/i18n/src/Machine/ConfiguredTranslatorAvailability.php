<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

/**
 * A fixed answer to TranslatorAvailability, decided where the translator is bound. MachineServiceProvider
 * binds it with true when preflow/ai is installed (AiTranslator) or config/ai.php
 * `translation.translator` names a translator, false otherwise (MissingAiTranslator).
 *
 * @since 0.83.0
 */
final readonly class ConfiguredTranslatorAvailability implements TranslatorAvailability
{
    /** @since 0.83.0 */
    public function __construct(private bool $available) {}

    public function available(): bool
    {
        return $this->available;
    }
}
