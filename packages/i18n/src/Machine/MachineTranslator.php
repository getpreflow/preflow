<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

use Preflow\Ai\AiUnavailable;
use Preflow\Ai\Tuning;

/** Translates text by machine. Callers depend on this, not on a provider, so tests and projects can swap it. */
interface MachineTranslator
{
    /**
     * One text on the fly.
     *
     * @throws AiUnavailable|TranslationFailed
     */
    public function translate(string $text, string $to, ?Tuning $tuning = null, ?string $from = null, TextFormat $format = TextFormat::Plain): string;

    /**
     * A failing unit becomes a Failed result, never an exception, so one bad text cannot stop a run.
     *
     * @param list<TranslationUnit> $units
     * @param string|null $note a one-off instruction for this run
     * @return list<TranslationResult> one per unit, in the same order
     * @throws AiUnavailable (only that) the provider cannot be used at all: the whole run stops
     */
    public function translateUnits(array $units, ?string $note = null): array;
}
