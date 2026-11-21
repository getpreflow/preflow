<?php

declare(strict_types=1);

namespace Preflow\View\Pdf;

use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\Purpose;
use Preflow\View\TemplateEngineInterface;

/**
 * Renders a PdfDocument's template with the site's template engine, as PDF
 * bytes or as an HTML page for a browser preview. There are no component
 * wrappers: the template's output, <style> blocks included, is the page.
 *
 * Each render (since 0.81.0) runs in a render frame of its own under the
 * caller's context (its viewer, locale and read mode) with purpose Pdf (or
 * the caller's internal purpose, inside the SEO audit say) and discarding:
 * CSS and page meta the templates collect stay out of the caller's page, and
 * mail, cookies and after-response work they queue are dropped (never sent),
 * while data the document writes (in resolveState()) is written: send mail
 * from the code that calls the renderer. The frame is part of the caller's
 * output (Contexts::part()): the page it is printed into records its reads and
 * refusals for the page cache and shares its CSP nonce. Two renders, nested or
 * one after the other, share nothing else. It needs a current render (a
 * request, a command, Contexts::run()).
 */
final class PdfRenderer
{
    /**
     * @param ?PdfEngine $engine null uses mPDF when it is installed
     */
    public function __construct(
        private readonly TemplateEngineInterface $templates,
        private ?PdfEngine $engine,
        private readonly PdfConfig $config,
    ) {
    }

    /**
     * The PDF's bytes. Needs a PDF engine (mPDF by default).
     *
     * @throws \Preflow\Core\Context\NoRenderContext outside every render
     */
    public function render(PdfDocument $doc): string
    {
        $engine = $this->engine();

        // The engine runs inside the scope too: scaled image copies must outlive the templates until mPDF has read them.
        return self::own(fn (): string => Blocks::forPdf($this->config, function () use ($doc, $engine): string {
            $doc->resolveState();
            $context = self::context($doc);
            $page = $doc->page();

            return $engine->render(
                self::htmlPage($this->templates->render($doc->template(), $context)),
                $page,
                $this->renderOptional($page->headerTemplate, $context),
                $this->renderOptional($page->footerTemplate, $context),
            );
        }));
    }

    /**
     * The same document as an HTML page, for a preview in the browser. It
     * needs no PDF library. Header and footer are shown above and below the
     * content; the building blocks render approximately (page numbers as
     * nothing, a page break as a dashed line).
     *
     * @throws \Preflow\Core\Context\NoRenderContext outside every render
     */
    public function html(PdfDocument $doc): string
    {
        return self::own(fn (): string => Blocks::forPreview(fn (): string => $this->previewHtml($doc)));
    }

    /**
     * $render in a frame of its own that is part of the caller's output (Contexts::part(): the page
     * a preview is printed into records its reads and refusals and prints its nonce): the caller's
     * context, as a PDF (inside an internal render, such as the SEO audit, with that render's
     * purpose), discarding what it queues.
     *
     * @param \Closure(): string $render
     */
    private static function own(\Closure $render): string
    {
        $ctx = Contexts::current();

        return Contexts::part(($ctx->purpose->isInternal() ? $ctx : $ctx->because(Purpose::Pdf))->discarding(), $render);
    }

    private function previewHtml(PdfDocument $doc): string
    {
        $doc->resolveState();
        $context = self::context($doc);
        $page = $doc->page();

        $header = $this->renderOptional($page->headerTemplate, $context);
        $footer = $this->renderOptional($page->footerTemplate, $context);
        $html = self::htmlPage($this->templates->render($doc->template(), $context));

        // Spliced, not preg_replace()d: "$1" or "\1" in rendered text must stay text.
        if ($header !== '' && preg_match('/<body\b[^>]*>/i', $html, $match, PREG_OFFSET_CAPTURE)) {
            $at = $match[0][1] + strlen($match[0][0]);
            $html = substr($html, 0, $at) . "\n<header>{$header}</header>" . substr($html, $at);
        }
        if ($footer !== '') {
            $at = strripos($html, '</body>');
            $html = substr($html, 0, (int) $at) . "<footer>{$footer}</footer>\n" . substr($html, (int) $at);
        }

        return $html;
    }

    private function engine(): PdfEngine
    {
        return $this->engine ??= new MpdfEngine($this->config);
    }

    private function renderOptional(?string $template, array $context): string
    {
        return $template === null ? '' : $this->templates->render($template, $context);
    }

    /**
     * A template that writes a whole document is used as it is; a fragment
     * gets a minimal UTF-8 page around it.
     */
    private static function htmlPage(string $html): string
    {
        if (preg_match('/^\s*(<!doctype\b|<html\b)/i', $html) && stripos($html, '</body>') !== false) {
            return $html;
        }

        return "<!DOCTYPE html>\n<html>\n<head>\n<meta charset=\"utf-8\">\n</head>\n<body>\n" . trim($html) . "\n</body>\n</html>\n";
    }

    /**
     * The template's variables: every initialised public property.
     *
     * @return array<string, mixed>
     */
    private static function context(PdfDocument $doc): array
    {
        $context = [];
        foreach ((new \ReflectionObject($doc))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if (!$property->isStatic() && $property->isInitialized($doc)) {
                $context[$property->getName()] = $property->getValue($doc);
            }
        }

        return $context;
    }
}
