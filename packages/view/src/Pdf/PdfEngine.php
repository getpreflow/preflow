<?php

declare(strict_types=1);

namespace Preflow\View\Pdf;

/**
 * Turns HTML into PDF bytes. MpdfEngine is the default; a site binds its own
 * implementation in the container to use another library.
 */
interface PdfEngine
{
    /**
     * @param string $html       the whole page, including its <style> blocks
     * @param string $headerHtml printed at the top of every page ('' for none)
     * @param string $footerHtml printed at the bottom of every page ('' for none)
     * @return string the PDF's bytes
     */
    public function render(string $html, PageSettings $page, string $headerHtml, string $footerHtml): string;
}
