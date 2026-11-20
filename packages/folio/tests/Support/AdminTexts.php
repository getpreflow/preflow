<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Support;

use Preflow\Core\Context\Contexts;
use Preflow\Folio\FolioText;
use Preflow\I18n\Translator;
use Preflow\Testing\ContextFactory;

/**
 * Folio's texts as an admin request gives them, without an app: $translator is the app's
 * translator (FolioText::translatorFrom()) and $fn runs in a render on the admin surface in
 * $translator's locale. Both are gone afterwards.
 */
trait AdminTexts
{
    /**
     * @template T
     * @param \Closure(): T $fn
     * @return T
     */
    private static function inAdmin(Translator $translator, \Closure $fn): mixed
    {
        FolioText::translatorFrom(static fn (): Translator => $translator);
        try {
            return Contexts::run(ContextFactory::forTest()->admin($translator->getLocale()), $fn);
        } finally {
            FolioText::translatorFrom(null);
        }
    }
}
