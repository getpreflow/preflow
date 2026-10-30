<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

/**
 * One piece of text to translate, independent of where it is stored. Sources
 * (Folio, a project) build units; the translator never learns about records.
 */
final readonly class TranslationUnit
{
    /** @param array<string,string> $context e.g. ['type' => 'Game', 'record' => 'Cascadia'] */
    public function __construct(
        /** The path, unique within one item. */
        public string $id,
        /** Human label such as "Step 3 › Option B". */
        public string $label,
        public string $source,
        public string $to,
        public string $from,
        public TextFormat $format = TextFormat::Plain,
        public ?string $kind = null,
        /** Outdated: the source the current translation was made from. */
        public ?string $previousSource = null,
        /** Outdated/redo: the current translation. */
        public ?string $current = null,
        public array $context = [],
        /** Field/kind-level instructions, already resolved by the source. */
        public ?string $tuning = null,
        /** Collection key, for glossary type limits. */
        public ?string $type = null,
    ) {}
}
