<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

/**
 * The admin's language while one of Folio's previews renders, so the marks' labels
 * (data-folio-label of a block, of a linked record) read in the editor's language although the
 * preview renders as a visitor, whose texts are English (FolioText::translator()).
 *
 * Folio's three preview renders (the live page preview and the type preview, PreviewController;
 * the whole-page preview's first render, PagePreviewController) set it from FolioText::locale()
 * while still on the admin surface, and reset it in a finally. FolioBlockPreviewMarks and
 * FolioRecordPreviewMarks translate in it when it is set, and as before when it is not.
 *
 * Request-scoped: fragments loaded later through a preview token (a component action, a lazy
 * part) are separate requests without the admin surface and keep the English label.
 *
 * @since 0.94.0
 */
final class PreviewLabelLanguage
{
    private ?string $locale = null;

    /** The language of the labels for the render that follows; null: as before (Folio's own texts). */
    public function set(?string $locale): void
    {
        $this->locale = $locale;
    }

    public function get(): ?string
    {
        return $this->locale;
    }

    /**
     * Runs $render with $locale set, and unsets it afterwards (also when the render throws).
     *
     * @template T
     * @param \Closure(): T $render
     * @return T
     */
    public function during(?string $locale, \Closure $render): mixed
    {
        $this->set($locale);
        try {
            return $render();
        } finally {
            $this->set(null);
        }
    }
}
