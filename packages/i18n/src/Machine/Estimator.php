<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

use Preflow\Ai\Ai;
use Preflow\Ai\TokenUsage;

/**
 * Sizes a run before it starts, so the admin can show what it will cost. Tokens are counted
 * as a quarter of the characters, which is close enough for European languages. The estimate
 * leaves out project tuning and glossary lines, which are small next to the texts.
 *
 * Translation requests ask for low reasoning (AiRequest::$lowReasoning): Gemini then does not
 * think at all, Claude at effort "low" thinks little or not at all. So no allowance for thinking
 * tokens is added; on Claude a run can cost a little more than estimated.
 */
final class Estimator
{
    /** Tokens per request for the JSON schema, roles and provider wrapping. */
    private const REQUEST_OVERHEAD = 200;

    /** A translation runs a little longer than its source, and the answer adds JSON. */
    private const OUTPUT_FACTOR = 1.3;

    public function __construct(
        private readonly Ai $ai,
        private readonly int $batchChars = 12000,
    ) {}

    /** @param list<TranslationUnit> $units */
    public function estimate(array $units): Estimate
    {
        $units = array_values($units);
        $batches = TranslationPrompt::batches($units, $this->batchChars);

        $input = 0;
        foreach ($batches as $batch) {
            $first = reset($batch);
            $system = implode("\n\n", TranslationPrompt::rules($first->from, $first->to));
            $input += self::tokens(mb_strlen($system) + mb_strlen(TranslationPrompt::prompt($batch))) + self::REQUEST_OVERHEAD;
        }

        $characters = array_sum(array_map(static fn (TranslationUnit $u): int => mb_strlen($u->source), $units));
        $output = (int) ceil(self::OUTPUT_FACTOR * self::tokens($characters));

        return new Estimate(
            count($units),
            $characters,
            $input,
            $output,
            $this->ai->pricing()->cost($this->ai->status()->model, new TokenUsage($input, $output)),
            count($batches),
        );
    }

    private static function tokens(int $chars): int
    {
        return (int) ceil($chars / 4);
    }
}
