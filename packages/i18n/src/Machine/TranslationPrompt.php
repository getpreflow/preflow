<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

use Preflow\I18n\LocaleNames;

/**
 * The batching and the prompt text, in one place for AiTranslator and Estimator: an estimate is
 * only honest when it sizes the very requests a run would send.
 *
 * @internal
 */
final class TranslationPrompt
{
    /**
     * The answer: one {n, text} per item. additionalProperties is required by Claude for object
     * schemas (the Gemini provider strips it).
     */
    public const SCHEMA = [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'properties' => ['n' => ['type' => 'integer'], 'text' => ['type' => 'string']],
            'required' => ['n', 'text'],
            'additionalProperties' => false,
        ],
    ];

    public const RECITATION_RETRY = 'Word it freely in your own words.';

    /**
     * The general translation rules: the only prompt prose, so tests and reviewers find it here.
     *
     * @return list<string>
     */
    public static function rules(string $from, string $to): array
    {
        $target = self::language($to);
        $direction = $from === ''
            ? "Translate the \"text\" of each item into {$target}."
            : 'Translate the "text" of each item from ' . self::language($from) . " into {$target}.";

        return [
            $direction,
            'Keep HTML tags, Markdown and placeholders such as {name} or :count exactly as they are; translate only the words around them.',
            'Never translate proper names, brand names or game names.',
            '"label", "kind" and "format" describe where an item is used; a "hint" holds instructions for that item only.',
            'An item with "previous" and "current" was translated before: its source changed from "previous" to "text". '
                . 'Adapt "current" to that change and keep its wording everywhere else.',
            'Answer only with the JSON array: one object {"n": …, "text": …} per item, with the item\'s n and its translation.',
        ];
    }

    /**
     * Context as a header line, then the items as compact JSON on the last line.
     *
     * @param array<int, TranslationUnit> $units
     */
    public static function prompt(array $units): string
    {
        $items = [];
        $contexts = [];
        $n = 0;
        foreach ($units as $unit) {
            $item = ['n' => ++$n, 'text' => $unit->source];
            if ($unit->format !== TextFormat::Plain) {
                $item['format'] = $unit->format->value;
            }
            $item['label'] = $unit->label;
            if ($unit->kind !== null && $unit->kind !== '') {
                $item['kind'] = $unit->kind;
            }
            if ($unit->previousSource !== null) {
                $item['previous'] = $unit->previousSource;
            }
            if ($unit->current !== null) {
                $item['current'] = $unit->current;
            }
            if ($unit->tuning !== null && trim($unit->tuning) !== '') {
                $item['hint'] = $unit->tuning;
            }
            $items[] = $item;

            $context = [];
            foreach ($unit->context as $key => $value) {
                $context[] = $key . ': ' . str_replace(["\r", "\n"], ' ', (string) $value);
            }
            if ($context !== []) {
                $contexts[implode(', ', $context)] = true;
            }
        }

        $json = json_encode($items, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $contexts === [] ? $json : 'Context: ' . implode('; ', array_keys($contexts)) . "\n" . $json;
    }

    /**
     * Units grouped by (from, to, type) in order of first appearance, each group packed into
     * requests of at most $batchChars characters. A unit over the budget goes alone.
     *
     * @param list<TranslationUnit> $units
     * @return list<array<int, TranslationUnit>> keys are the positions in $units
     */
    public static function batches(array $units, int $batchChars): array
    {
        $groups = [];
        foreach ($units as $i => $unit) {
            $groups[$unit->from . "\0" . $unit->to . "\0" . ($unit->type ?? '')][$i] = $unit;
        }

        $batches = [];
        foreach ($groups as $group) {
            $batch = [];
            $chars = 0;
            foreach ($group as $i => $unit) {
                $size = self::chars($unit);
                if ($batch !== [] && $chars + $size > $batchChars) {
                    $batches[] = $batch;
                    $batch = [];
                    $chars = 0;
                }
                $batch[$i] = $unit;
                $chars += $size;
            }
            if ($batch !== []) {
                $batches[] = $batch;
            }
        }

        return $batches;
    }

    /** The text a unit sends: source plus, for an outdated one, the old source and current translation. */
    public static function chars(TranslationUnit $unit): int
    {
        return mb_strlen($unit->source) + mb_strlen($unit->current ?? '') + mb_strlen($unit->previousSource ?? '');
    }

    private static function language(string $locale): string
    {
        $name = LocaleNames::native($locale);

        return strcasecmp($name, $locale) === 0 ? $locale : "{$name} ({$locale})";
    }
}
