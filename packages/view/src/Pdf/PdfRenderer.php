<?php

declare(strict_types=1);

namespace Preflow\View\Pdf;

use Preflow\View\TemplateEngineInterface;

/**
 * Renders a PdfDocument's template with the site's template engine, as PDF
 * bytes or as an HTML page for a browser preview. There are no component
 * wrappers: the template's output, <style> blocks included, is the page.
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
     */
    public function render(PdfDocument $doc): string
    {
        $doc->resolveState();
        $context = self::context($doc);
        $page = $doc->page();

        return $this->engine()->render(
            self::htmlPage($this->templates->render($doc->template(), $context)),
            $page,
            $this->renderOptional($page->headerTemplate, $context),
            $this->renderOptional($page->footerTemplate, $context),
        );
    }

    /**
     * The same document as an HTML page, for a preview in the browser. It
     * needs no PDF library. Header and footer are shown above and below the
     * content; page numbers and page breaks only work in the PDF.
     */
    public function html(PdfDocument $doc): string
    {
        $doc->resolveState();
        $context = self::context($doc);
        $page = $doc->page();

        $header = $this->renderOptional($page->headerTemplate, $context);
        $footer = $this->renderOptional($page->footerTemplate, $context);
        $html = self::htmlPage($this->templates->render($doc->template(), $context));

        if ($header !== '') {
            $html = (string) preg_replace('/<body\b[^>]*>/i', "\$0\n<header>{$header}</header>", $html, 1);
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
