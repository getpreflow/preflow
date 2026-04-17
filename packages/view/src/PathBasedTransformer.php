<?php

declare(strict_types=1);

namespace Preflow\View;

final class PathBasedTransformer implements ImageUrlTransformer
{
    public function transform(string $path, int $width, string $format, int $quality): string
    {
        $separator = str_contains($path, '?') ? '&' : '?';
        return $path . $separator . http_build_query(['w' => $width, 'fm' => $format, 'q' => $quality]);
    }
}
