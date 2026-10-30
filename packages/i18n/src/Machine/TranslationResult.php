<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

/** The result for one unit; warnings flag a usable text that deserves a second look. */
final readonly class TranslationResult
{
    /** @param list<string> $warnings e.g. 'glossary:Worker Placement' */
    public function __construct(
        public string $id,
        public UnitStatus $status,
        public ?string $text,
        public array $warnings = [],
        public ?string $error = null,
    ) {}
}
