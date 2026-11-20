<?php

declare(strict_types=1);

namespace Preflow\View\Pdf;

/**
 * The PDF renders open in one render frame, innermost last (a frame slot): which building blocks
 * apply (mPDF markup or browser HTML) and the scaled image copies to delete when each ends. A
 * render in a frame of its own (another PDF, a page rendered inside a PDF) starts with none.
 *
 * @internal Blocks
 * @since 0.81.0
 */
final class BlockRenders
{
    /** @var list<array{config: ?PdfConfig, temp: list<string>}> */
    public array $open = [];
}
