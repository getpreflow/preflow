<?php

declare(strict_types=1);

namespace Preflow\Ai\Draft;

/**
 * What to draft: the brief (what it should say, in the person's words), the language to write in
 * (a code such as "de"), the length, and context: label => text facts about where the text goes,
 * such as the title and the field label. Context is data about the place, never instructions.
 * $elements are the HTML elements the caller's editor keeps (lower-case names); the draft uses
 * only those. The default is a generic set for rich text.
 *
 * @since 0.77
 */
final readonly class DraftRequest
{
    /** The generic set of elements a draft may use when the caller names none. */
    public const array ELEMENTS = ['p', 'h2', 'h3', 'ul', 'ol', 'li', 'strong', 'em', 'a'];

    /**
     * @param array<string, string> $context label => text
     * @param list<string> $elements the HTML elements the caller's editor keeps
     */
    public function __construct(
        public string $brief,
        public string $language,
        public DraftLength $length = DraftLength::Medium,
        public array $context = [],
        public array $elements = self::ELEMENTS,
    ) {}

    /**
     * The elements as names only (lower case, each once); the generic set when none is usable.
     *
     * @return list<string>
     */
    public function elements(): array
    {
        $out = [];
        foreach ($this->elements as $name) {
            $name = is_string($name) ? strtolower(trim($name)) : '';
            if (preg_match('/\A[a-z][a-z0-9]*\z/', $name) === 1 && !in_array($name, $out, true)) {
                $out[] = $name;
            }
        }

        return $out !== [] ? $out : self::ELEMENTS;
    }
}
