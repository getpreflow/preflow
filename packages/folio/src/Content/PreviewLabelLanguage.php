<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Core\Context\Facet;

/**
 * The admin's language while one of Folio's previews renders, so the marks' labels
 * (data-folio-label of a block, of a linked record) read in the editor's language although the
 * preview renders as a visitor, whose texts are English (FolioText::translator()).
 *
 * A facet of the preview's render context: Folio's three preview renders (the live page preview
 * and the type preview, PreviewController; the whole-page preview, PagePreviewController) add it
 * with RenderContext::with() from FolioText::locale() while still on the admin surface.
 * FolioBlockPreviewMarks and FolioRecordPreviewMarks read it from the current context
 * (Contexts::current()->get(PreviewLabelLanguage::class)) and translate in it when it is there, and
 * as before when it is not. It ends with the preview's context, so there is nothing to reset.
 *
 * Fragments loaded later through a preview token (a component action, a lazy part) are separate
 * requests without the admin surface and keep the English label.
 *
 * @since 0.94.0
 */
final readonly class PreviewLabelLanguage implements Facet
{
    public function __construct(public string $locale) {}

    /** Only Folio's previews carry it: never what an anonymous visitor gets. */
    public function isPublicDefault(): bool
    {
        return false;
    }

    public function memoKey(): string
    {
        return 'folio-labels:' . $this->locale;
    }
}
