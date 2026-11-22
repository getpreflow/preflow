<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

/**
 * The machine translator of an app without preflow/ai (AiPackage::installed() is false) that names
 * no translator of its own (config/ai.php `translation.translator`). Never
 * available, so Folio offers no Translate and the worker leaves jobs alone (translate:work stays
 * quiet, as with AI off); translate:plan and a job that is stepped say MESSAGE instead of failing
 * on a missing class.
 *
 * @since 0.80.0
 */
final class MissingAiTranslator implements MachineTranslator
{
    public const MESSAGE = 'Machine translation needs preflow/ai (composer require preflow/ai) or a translator of your own (config/ai.php translation.translator).';

    public function available(): bool
    {
        return false;
    }

    public function estimateCost(Estimate $size): ?float
    {
        return null;
    }

    /** @throws TranslationFailed always, with MESSAGE */
    public function translate(string $text, string $to, ?TranslationTuning $tuning = null, ?string $from = null, TextFormat $format = TextFormat::Plain): string
    {
        throw new TranslationFailed(self::MESSAGE);
    }

    /** @throws TranslationFailed always, with MESSAGE */
    public function translateUnits(array $units, ?string $note = null): array
    {
        throw new TranslationFailed(self::MESSAGE);
    }
}
