<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Data\TypeLabels;
use Preflow\I18n\Translator;

/**
 * Names a type the way Folio does ({@see DisplayLabels::typeIn()}), for texts of other packages that
 * are made outside an admin request (a mail a visitor's action triggers): with the app's translator.
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
        return $this->labels->typeIn($type, $locale, ($this->translator)());
    }
}
