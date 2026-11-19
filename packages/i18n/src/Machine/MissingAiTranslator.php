<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

/**
 * The machine translator of an app without preflow/ai (AiPackage::installed() is false). Never
 * available, so Folio offers no Translate and the worker leaves jobs alone; anything that still
 * tries (translate:plan, translate:work, a job queued before the package was removed) is told
 * MESSAGE instead of failing on a missing class.
 *
 * @since 0.80.0
 */
final class MissingAiTranslator implements MachineTranslator
{
    public const MESSAGE = 'Machine translation needs preflow/ai: run composer require preflow/ai.';

    public function available(): bool
    {
        return false;
    }

    public function estimateCost(Estimate $size): ?float
    {
        return null;
    }

    /** @throws TranslationFailed always, with MESSAGE */
    public function translate(string $text, string $to, ?\Preflow\Ai\Tuning $tuning = null, ?string $from = null, TextFormat $format = TextFormat::Plain): string
    {
        throw new TranslationFailed(self::MESSAGE);
    }

    /** @throws TranslationFailed always, with MESSAGE */
    public function translateUnits(array $units, ?string $note = null): array
    {
        throw new TranslationFailed(self::MESSAGE);
    }
}
