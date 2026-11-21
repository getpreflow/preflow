<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Pdf\Fixtures;

use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\Site;
use Preflow\Core\Context\SystemClock;
use Preflow\View\Pdf\PdfDocument;
use Preflow\View\Pdf\PdfRenderer;

/**
 * A PdfRenderer called from a command's render, as a site calls it from a request or a command:
 * PdfRenderer opens its frame from the current render (since 0.81.0), so these unit tests give it one.
 */
final class InFrame
{
    public function __construct(private readonly PdfRenderer $renderer) {}

    public function render(PdfDocument $doc): string
    {
        return self::run(fn (): string => $this->renderer->render($doc));
    }

    public function html(PdfDocument $doc): string
    {
        return self::run(fn (): string => $this->renderer->html($doc));
    }

    /**
     * @template T
     * @param \Closure(): T $fn
     * @return T
     */
    public static function run(\Closure $fn): mixed
    {
        return Contexts::run(RenderContext::console(new Site('http://localhost', ['en'], 'en'), new SystemClock()), $fn);
    }
}
