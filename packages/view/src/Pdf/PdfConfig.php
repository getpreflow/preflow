<?php

declare(strict_types=1);

namespace Preflow\View\Pdf;

/**
 * The `pdf` section of config/view.php.
 */
final readonly class PdfConfig
{
    /**
     * @param string                $tempDir     where the engine keeps its temp files and font cache
     * @param array<string, string> $fonts       font name (as used in CSS font-family) => TTF/OTF file path
     * @param string                $defaultFont font for text without a font-family
     */
    public function __construct(
        public string $tempDir,
        public array $fonts = [],
        public string $defaultFont = 'dejavusanscondensed',
    ) {
    }
}
