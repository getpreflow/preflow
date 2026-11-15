<?php

declare(strict_types=1);

namespace Preflow\Ai\Draft;

/**
 * What to draft: the brief (what it should say, in the person's words), the language to write in
 * (a code such as "de"), the length, and context: label => text facts about where the text goes,
 * such as the title and the field label. Context is data about the place, never instructions.
 *
 * @since 0.77
 */
final readonly class DraftRequest
{
    /** @param array<string, string> $context label => text */
    public function __construct(
        public string $brief,
        public string $language,
        public DraftLength $length = DraftLength::Medium,
        public array $context = [],
    ) {}
}
