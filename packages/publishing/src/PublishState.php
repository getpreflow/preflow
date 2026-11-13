<?php

declare(strict_types=1);

namespace Preflow\Publishing;

/** Derived, never stored: compares the working copy with its published snapshot. */
enum PublishState: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Changed = 'changed';

    /** In English; an admin shows its own words for the value (Folio: folio.publishing.<value>). */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
            self::Changed => 'Changed',
        };
    }
}
