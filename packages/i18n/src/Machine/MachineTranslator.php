<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

use Preflow\Ai\AiUnavailable;
use Preflow\Ai\Tuning;

/**
 * Translates text by machine. Callers depend on this, not on a provider, so tests and projects can
 * swap it (config/ai.php `translation.translator`). A translator answers its own availability and
 * price: Folio's Translate, the worker, `translate:work` and the estimate ask it, not the AI
 * connection, so a translator that is no AI (a translation API) needs no AI key. AiTranslator, the
 * default, answers through preflow/ai.
 */
interface MachineTranslator
{
    /**
     * Whether it can translate now (configured, its key present). False hides Folio's Translate and
     * leaves queued jobs waiting. Cheap and never throws: it is asked on admin requests. Since 0.66.
     */
    public function available(): bool;

    /**
     * What translating texts of $size would cost in USD, null when unknown (never 0 for "don't know").
     * $size counts units, characters and requests (and the tokens an AI model would use); its own
     * costUsd is null. Since 0.66.
     */
    public function estimateCost(Estimate $size): ?float;

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
