<?php

declare(strict_types=1);

namespace Preflow\View;

interface ImageUrlTransformer
{
    public function transform(string $path, int $width, string $format, int $quality): string;
}
