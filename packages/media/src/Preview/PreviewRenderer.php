<?php

declare(strict_types=1);

namespace Preflow\Media\Preview;

/**
 * Renders a preview image of a file that is not an image itself (a PDF's first
 * page). Registered on PreviewRenderers; MediaLibrary asks it on upload and
 * replace, only with the asset's own file path.
 */
interface PreviewRenderer
{
    public function supports(string $mime): bool;

    /**
     * The path of a temp PNG in sRGB, $longSide pixels on its long side, which the
     * caller moves into place; null when nothing could be rendered.
     */
    public function render(string $file, int $longSide): ?string;
}
