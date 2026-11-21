<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Fixtures\Isolation;

use Preflow\View\Pdf\PdfDocument;

/** A PDF of the same line the page prints. */
final class IsolationDocument extends PdfDocument
{
    public function __construct(private readonly string $template) {}

    public function template(): string
    {
        return $this->template;
    }
}
