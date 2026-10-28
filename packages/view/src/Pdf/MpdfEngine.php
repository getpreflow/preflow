<?php

declare(strict_types=1);

namespace Preflow\View\Pdf;

/**
 * The default PdfEngine, built on mPDF (pure PHP, so it runs on shared
 * hosting). mPDF stays an optional dependency: sites that never make a PDF
 * should not have to install it. Only its temp files and font cache touch
 * the disk, in the configured temp dir; the PDF is returned as bytes.
 */
final class MpdfEngine implements PdfEngine
{
    public function __construct(private readonly PdfConfig $config)
    {
        // Checked before any mPDF class is touched, so a missing library gives this message and not an autoload fatal.
        if (!self::available()) {
            throw new \RuntimeException('PDFs need the mPDF library. Install it with: composer require mpdf/mpdf');
        }
    }

    /**
     * Whether mPDF is installed.
     */
    public static function available(): bool
    {
        return class_exists('Mpdf\Mpdf');
    }

    public function render(string $html, PageSettings $page, string $headerHtml, string $footerHtml): string
    {
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => $page->width !== null ? [$page->width, $page->height] : $page->format,
            // A custom size is the page as given; mPDF would swap it for 'L'.
            'orientation' => $page->width !== null ? 'P' : $page->orientation,
            'margin_top' => $page->marginTop,
            'margin_right' => $page->marginRight,
            'margin_bottom' => $page->marginBottom,
            'margin_left' => $page->marginLeft,
            'default_font' => self::fontKey($this->config->defaultFont),
            'tempDir' => $this->tempDir(),
            ...$this->fonts(),
        ]);

        $mpdf->WriteHTML(self::withHeaderAndFooter($html, $headerHtml, $footerHtml));

        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    /**
     * Header and footer as named mPDF blocks that an @page rule attaches to
     * every page. SetHTMLHeader() would be dropped as soon as the document's
     * own CSS has an @page rule (margins, size), which templates often do.
     */
    private static function withHeaderAndFooter(string $html, string $headerHtml, string $footerHtml): string
    {
        $blocks = '';
        $page = [];
        if ($headerHtml !== '') {
            $blocks .= '<htmlpageheader name="preflowHeader">' . $headerHtml . '</htmlpageheader>';
            $page[] = 'header: html_preflowHeader;';
        }
        if ($footerHtml !== '') {
            $blocks .= '<htmlpagefooter name="preflowFooter">' . $footerHtml . '</htmlpagefooter>';
            $page[] = 'footer: html_preflowFooter;';
        }
        if ($blocks === '') {
            return $html;
        }
        $blocks = '<style>@page { ' . implode(' ', $page) . ' }</style>' . $blocks;

        if (preg_match('/<body\b[^>]*>/i', $html, $match, PREG_OFFSET_CAPTURE)) {
            $at = $match[0][1] + strlen($match[0][0]);

            return substr($html, 0, $at) . $blocks . substr($html, $at);
        }

        return $blocks . $html;
    }

    /**
     * mPDF's font settings with the configured fonts added to its bundled
     * ones. A configured font is one file, its regular style; a name that
     * mPDF already uses for a bundled family (or translates to one) is
     * refused, since the single file would replace that family's bold and
     * italic too.
     *
     * @return array{fontDir?: list<string>, fontdata?: array<string, array<string, string>>}
     */
    private function fonts(): array
    {
        if ($this->config->fonts === []) {
            return [];
        }

        $dirs = (new \Mpdf\Config\ConfigVariables())->getDefaults()['fontDir'];
        $defaults = (new \Mpdf\Config\FontVariables())->getDefaults();
        $fontdata = $defaults['fontdata'];
        foreach ($this->config->fonts as $name => $file) {
            $key = self::fontKey((string) $name);
            if (isset($defaults['fontdata'][$key]) || isset($defaults['fonttrans'][$key])) {
                throw new \InvalidArgumentException(
                    "The PDF font name '{$name}' is a font family bundled with mPDF; register the file under another name.",
                );
            }
            if (!is_file($file)) {
                throw new \InvalidArgumentException("The PDF font '{$name}' points to '{$file}', which is not a file.");
            }
            $dirs[] = dirname($file);
            $fontdata[$key] = ['R' => basename($file)];
        }

        return ['fontDir' => array_values(array_unique($dirs)), 'fontdata' => $fontdata];
    }

    /**
     * How mPDF names a font family: CSS font-family lowercased, without spaces.
     */
    private static function fontKey(string $name): string
    {
        return strtolower(str_replace(' ', '', $name));
    }

    private function tempDir(): string
    {
        $dir = $this->config->tempDir;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("The PDF temp dir '{$dir}' cannot be created.");
        }

        return $dir;
    }
}
