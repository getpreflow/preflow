<?php

declare(strict_types=1);

namespace Preflow\I18n;

/**
 * How complete a record is in one language: every text the default language has is there, some
 * are, or the record is not in that language at all. Anything that counts a record's texts per
 * language (Folio does, from its field types) says it with this, so an admin can show the three
 * states without knowing how they were counted.
 *
 * @since 0.75
 */
enum Completeness: string
{
    case Complete = 'complete';
    case Partial = 'partial';
    case Missing = 'missing';

    /**
     * The state of a language: not visible in it → Missing; nothing to translate ($total 0) or
     * nothing missing → Complete; every text missing → Missing; else Partial.
     *
     * @param bool $visible whether the record is shown in the language
     * @param int  $total   the texts the default language has
     * @param int  $missing those of them the language lacks
     */
    public static function of(bool $visible, int $total, int $missing): self
    {
        if (!$visible) {
            return self::Missing;
        }
        if ($total === 0 || $missing === 0) {
            return self::Complete;
        }

        return $missing >= $total ? self::Missing : self::Partial;
    }
}
