<?php

declare(strict_types=1);

namespace Preflow\Folio\Publishing;

/** Derived, never stored: compares the working copy with its published snapshot. */
enum PublishState: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Changed = 'changed';

    /** In the interface language during an admin request (FolioText), else English. */
    public function label(): string
    {
        return match ($this) {
            self::Draft => \Preflow\Folio\FolioText::get('folio.publishing.draft'),
            self::Published => \Preflow\Folio\FolioText::get('folio.publishing.published'),
            self::Changed => \Preflow\Folio\FolioText::get('folio.publishing.changed'),
        };
    }
}
