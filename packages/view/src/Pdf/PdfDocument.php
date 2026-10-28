<?php

declare(strict_types=1);

namespace Preflow\View\Pdf;

/**
 * A PDF written like a component: a class whose public properties are its
 * template's variables. The template is rendered by the site's template
 * engine (Twig or Blade) and turned into PDF bytes by PdfRenderer.
 */
abstract class PdfDocument
{
    /**
     * The template the site's engine renders: a name it resolves
     * ("pdf/certificate.twig", "@folio/...") or an absolute path.
     */
    abstract public function template(): string;

    /**
     * Load what the template needs (records, computed values) into public
     * properties. Runs before every render and preview.
     */
    public function resolveState(): void
    {
    }

    public function page(): PageSettings
    {
        return new PageSettings();
    }

    /** The name a browser saves the PDF under. */
    public function fileName(): string
    {
        return 'document.pdf';
    }
}
