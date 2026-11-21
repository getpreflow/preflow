<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Isolation;

use Preflow\View\Pdf\PdfDocument;

/** A PDF of the line the record page prints. */
final class IsolationDocument extends PdfDocument
{
    public function __construct(private readonly string $template) {}

    public function template(): string
    {
        return $this->template;
    }
}
