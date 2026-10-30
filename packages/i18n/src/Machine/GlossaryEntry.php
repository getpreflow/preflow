<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

/** A term with a fixed rendering per locale; a null rendering means "keep the term as is". */
final readonly class GlossaryEntry
{
    /**
     * @param array<string,string|null> $translations locale => required rendering, null = keep
     * @param list<string> $types collection keys the entry is limited to; empty = all
     */
    public function __construct(
        public string $term,
        public array $translations,
        public array $types = [],
        public ?string $note = null,
    ) {}

    /** The rendering a result must contain for $locale. */
    public function required(string $locale): string
    {
        return $this->translations[$locale] ?? $this->term;
    }
}
