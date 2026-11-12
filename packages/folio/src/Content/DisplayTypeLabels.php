<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Data\TypeLabels;
use Preflow\Folio\FolioText;
use Preflow\I18n\Translator;

/**
 * Names a type the way Folio does ({@see DisplayLabels::type()}), for texts of other packages that
 * are made outside an admin request (a mail a visitor's action triggers): with the app's translator,
 * as FolioText has only Folio's English words there.
 */
final class DisplayTypeLabels implements TypeLabels
{
    /** @param \Closure(): Translator $translator the app's translator, resolved on use */
    public function __construct(
        private readonly DisplayLabels $labels,
        private readonly \Closure $translator,
    ) {}

    public function label(string $type, string $locale): string
    {
        $previous = FolioText::use(($this->translator)());
        try {
            return $this->labels->type($type, $locale);
        } finally {
            FolioText::use($previous);
        }
    }
}
