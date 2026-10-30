<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

/** Project terms that must be translated one fixed way (or left alone). */
final class Glossary
{
    /** @param list<GlossaryEntry> $entries */
    public function __construct(private readonly array $entries) {}

    /**
     * @param list<TranslationUnit> $units
     * @return list<GlossaryEntry>
     */
    public function relevant(array $units, string $to): array
    {
        $out = [];
        foreach ($this->entries as $entry) {
            if (!array_key_exists($to, $entry->translations)) {
                continue;
            }
            foreach ($units as $unit) {
                if ($this->applies($entry, $unit)) {
                    $out[] = $entry;
                    break;
                }
            }
        }

        return $out;
    }

    /** @return list<string> */
    public function mismatches(TranslationUnit $unit, string $result): array
    {
        $missing = [];
        foreach ($this->entries as $entry) {
            if (!array_key_exists($unit->to, $entry->translations) || !$this->applies($entry, $unit)) {
                continue;
            }
            if (mb_stripos($result, $entry->required($unit->to)) === false) {
                $missing[] = $entry->term;
            }
        }

        return $missing;
    }

    /** @return list<array<string,mixed>> */
    public function toArray(): array
    {
        return array_map(static fn (GlossaryEntry $e): array => [
            'term' => $e->term,
            'translations' => $e->translations,
            'types' => $e->types,
            'note' => $e->note,
        ], $this->entries);
    }

    /** @param list<array<string,mixed>> $rows */
    public static function fromArray(array $rows): self
    {
        return new self(array_map(static fn (array $r): GlossaryEntry => new GlossaryEntry(
            (string) $r['term'],
            (array) ($r['translations'] ?? []),
            array_values((array) ($r['types'] ?? [])),
            isset($r['note']) ? (string) $r['note'] : null,
        ), $rows));
    }

    private function applies(GlossaryEntry $entry, TranslationUnit $unit): bool
    {
        if ($entry->types !== [] && !in_array($unit->type, $entry->types, true)) {
            return false;
        }

        // Letters/digits as boundaries, not \b: \b misbehaves next to terms that start or end in a non-word character.
        $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($entry->term, '/') . '(?![\p{L}\p{N}])/iu';

        return preg_match($pattern, $unit->source) === 1;
    }
}
