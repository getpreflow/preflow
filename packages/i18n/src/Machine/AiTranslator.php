<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

use Preflow\Ai\Ai;
use Preflow\Ai\AiClient;
use Preflow\Ai\AiRequest;
use Preflow\Ai\AiRequestFailed;
use Preflow\Ai\AiResponse;
use Preflow\Ai\AiResponseInvalid;
use Preflow\Ai\StopReason;
use Preflow\Ai\Tuning;

/**
 * Translates units in batched JSON requests through the configured AI provider.
 *
 * A request that the provider withholds (blocked, truncated, refused or failed) is split in
 * halves and retried down to single units, so one problematic text costs only itself. Each
 * answer is checked: markup must survive, and glossary terms not honoured become warnings.
 */
final class AiTranslator implements MachineTranslator, ReportsCost
{
    /** @var (\Closure(float): void)|null */
    private ?\Closure $costListener = null;

    public function __construct(
        private readonly Ai $ai,
        private readonly TranslationSettings $settings,
        private readonly int $batchChars = 12000,
    ) {}

    public function reportCostTo(?\Closure $listener): void
    {
        $this->costListener = $listener;
    }

    public function translate(string $text, string $to, ?Tuning $tuning = null, ?string $from = null, TextFormat $format = TextFormat::Plain): string
    {
        $unit = new TranslationUnit(id: 'text', label: 'Text', source: $text, to: $to, from: $from ?? '', format: $format);
        $result = $this->run([$unit], null, $tuning ?? new Tuning())[0];

        if ($result->status !== UnitStatus::Translated || $result->text === null) {
            throw new TranslationFailed('The translation failed: ' . ($result->error ?? 'no answer') . '.');
        }

        return $result->text;
    }

    public function translateUnits(array $units, ?string $note = null): array
    {
        return $this->run(array_values($units), $note, new Tuning());
    }

    /**
     * @param list<TranslationUnit> $units
     * @return list<TranslationResult>
     */
    private function run(array $units, ?string $note, Tuning $extra): array
    {
        if ($units === []) {
            return [];
        }
        // Before anything else: with AI off the whole run stops here (AiUnavailable).
        $client = $this->ai->client();
        $glossary = $this->settings->glossary();

        $results = [];
        foreach (TranslationPrompt::batches($units, $this->batchChars) as $batch) {
            $first = reset($batch);
            $base = new Tuning(TranslationPrompt::rules($first->from, $first->to))
                ->merge($this->settings->tuning($first->to, $first->type))
                ->merge($extra);
            $this->translateBatch($client, $batch, $base, $glossary, $note, $results);
        }
        ksort($results);

        return array_values($results);
    }

    /**
     * @param array<int, TranslationUnit> $batch
     * @param array<int, TranslationResult> $results filled by position
     */
    private function translateBatch(AiClient $client, array $batch, Tuning $base, Glossary $glossary, ?string $note, array &$results): void
    {
        [$response, $items, $reason] = $this->send($client, $batch, $base, $glossary, $note, null);

        if ($reason !== null && count($batch) > 1) {
            // Nothing in this request was answered, so the halves re-send no answered unit.
            $half = intdiv(count($batch) + 1, 2);
            $this->translateBatch($client, array_slice($batch, 0, $half, true), $base, $glossary, $note, $results);
            $this->translateBatch($client, array_slice($batch, $half, null, true), $base, $glossary, $note, $results);

            return;
        }

        // Gemini withholds text that resembles known sources; a freer wording usually gets through.
        if ($reason !== null && $response?->stop === StopReason::Blocked && strtoupper((string) $response->detail) === 'RECITATION') {
            [, $items, $reason] = $this->send($client, $batch, $base->with(TranslationPrompt::RECITATION_RETRY), $glossary, $note, 1.0);
        }

        if ($reason !== null || $items === null) {
            $this->fail($batch, $reason ?? 'no answer', $results);

            return;
        }

        $this->collect($batch, $items, $glossary, $results);
    }

    /**
     * @param array<int, TranslationUnit> $batch
     * A broken answer (invalid JSON, not a list) counts as a failed request like a blocked one, so
     * it is split and retried too: a long batch is where a model most often garbles the JSON.
     *
     * @return array{0: ?AiResponse, 1: ?list<mixed>, 2: ?string} the response, the decoded items, and the reason when the request as a whole failed
     */
    private function send(AiClient $client, array $batch, Tuning $base, Glossary $glossary, ?string $note, ?float $temperature): array
    {
        $units = array_values($batch);
        $lines = [];
        foreach ($glossary->relevant($units, $units[0]->to) as $entry) {
            $rendering = $entry->translations[$units[0]->to] ?? null;
            $line = '"' . $entry->term . '" → ' . ($rendering === null ? 'keep as is' : '"' . $rendering . '"');
            $lines[] = $entry->note !== null && trim($entry->note) !== '' ? $line . ' (' . trim($entry->note) . ')' : $line;
        }
        $tuning = $base;
        if ($lines !== []) {
            $tuning = $tuning->with("Glossary, use these renderings:\n" . implode("\n", $lines));
        }
        $tuning = $tuning->with($note ?? '');

        $chars = array_sum(array_map(TranslationPrompt::chars(...), $units));
        $request = new AiRequest(
            'translate',
            TranslationPrompt::prompt($units),
            $tuning,
            TranslationPrompt::SCHEMA,
            $temperature,
            // The answer repeats every text translated, plus JSON; a lone long text needs more than the default.
            min(32000, max(8000, (int) ceil($chars / 2))),
        );

        try {
            $response = $client->complete($request);
        } catch (AiRequestFailed $e) {
            return [null, null, $e->getMessage()];
        }
        $this->reportCost($response);

        if ($response->stop !== StopReason::Complete) {
            $detail = $response->detail !== null && $response->detail !== '' ? ' (' . $response->detail . ')' : '';

            return [$response, null, $response->stop->value . $detail];
        }

        try {
            $data = $response->json();
        } catch (AiResponseInvalid $e) {
            return [$response, null, $e->getMessage()];
        }
        if (!is_array($data) || !array_is_list($data)) {
            return [$response, null, 'the answer is not a list'];
        }

        return [$response, $data, null];
    }

    /**
     * @param array<int, TranslationUnit> $batch
     * @param list<mixed> $data the decoded answer
     * @param array<int, TranslationResult> $results
     */
    private function collect(array $batch, array $data, Glossary $glossary, array &$results): void
    {
        // The first answer for each n counts; unknown and repeated numbers are ignored.
        $byN = [];
        foreach ($data as $item) {
            if (is_array($item) && is_int($item['n'] ?? null) && is_string($item['text'] ?? null)) {
                $byN[$item['n']] ??= $item['text'];
            }
        }

        $n = 0;
        foreach ($batch as $i => $unit) {
            $text = $byN[++$n] ?? null;
            $results[$i] = match (true) {
                $text === null => new TranslationResult($unit->id, UnitStatus::Failed, null, error: 'no answer'),
                !MarkupCheck::matches($unit->source, $text, $unit->format) => new TranslationResult($unit->id, UnitStatus::Failed, null, error: 'markup changed'),
                default => new TranslationResult(
                    $unit->id,
                    UnitStatus::Translated,
                    $text,
                    array_map(static fn (string $term): string => 'glossary:' . $term, $glossary->mismatches($unit, $text)),
                ),
            };
        }
    }

    /**
     * @param array<int, TranslationUnit> $batch
     * @param array<int, TranslationResult> $results
     */
    private function fail(array $batch, string $reason, array &$results): void
    {
        foreach ($batch as $i => $unit) {
            $results[$i] = new TranslationResult($unit->id, UnitStatus::Failed, null, error: $reason);
        }
    }

    /** Priced like the usage log: by the model the provider reports, else by the configured one; unpriced counts as 0. */
    private function reportCost(AiResponse $response): void
    {
        if ($this->costListener === null) {
            return;
        }
        $pricing = $this->ai->pricing();
        $cost = $pricing->cost($response->model, $response->usage) ?? $pricing->cost($this->ai->status()->model, $response->usage) ?? 0.0;
        ($this->costListener)($cost);
    }
}
