<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Pdf\Fixtures;

use Preflow\View\Pdf\PageSettings;
use Preflow\View\Pdf\PdfDocument;

/**
 * A one-page certificate, with its templates in both engines' syntax next
 * to it; the test picks the extension of the engine it renders with.
 */
final class CertificateDocument extends PdfDocument
{
    public string $game = '';

    public function __construct(
        public string $name,
        private readonly string $extension = 'twig',
        private readonly PageSettings $pageSettings = new PageSettings(),
    ) {
    }

    public function resolveState(): void
    {
        $this->game = 'Brass: Birmingham';
    }

    public function template(): string
    {
        return __DIR__ . '/certificate.' . $this->extension;
    }

    public function page(): PageSettings
    {
        return $this->pageSettings;
    }

    public function fileName(): string
    {
        return 'certificate.pdf';
    }

    /** The absolute path of another fixture template in this engine's syntax. */
    public function fixture(string $name): string
    {
        return __DIR__ . '/' . $name . '.' . $this->extension;
    }
}
