<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\I18n\Completeness;

/**
 * How far a record is in one content language (TranslationStatus::state()): its completeness and
 * the counts behind it. $total is the texts the default language has, $missing those the language
 * lacks, $outdated its translations made from a default text that has changed since, $unchecked
 * its machine translations nobody has checked yet.
 *
 * @since 0.75
 */
final readonly class LocaleState
{
    public function __construct(
        public Completeness $completeness,
        public int $total,
        public int $missing,
        public int $outdated,
        public int $unchecked,
    ) {}

    /** Whether the language holds texts to look at again: outdated or not checked. */
    public function stale(): bool
    {
        return $this->outdated + $this->unchecked > 0;
    }
}
