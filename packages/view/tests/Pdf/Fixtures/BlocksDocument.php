<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Pdf\Fixtures;

use Preflow\View\Pdf\PageSettings;
use Preflow\View\Pdf\PdfDocument;

/**
 * A document whose template the test writes itself; its values reach the
 * template as `vars`.
 */
final class BlocksDocument extends PdfDocument
{
    /**
     * @param array<string, mixed> $vars
     */
    public function __construct(
        private readonly string $file,
        public array $vars = [],
        private readonly PageSettings $settings = new PageSettings(),
    ) {
    }

    public function template(): string
    {
        return $this->file;
    }

    public function page(): PageSettings
    {
        return $this->settings;
    }
}
