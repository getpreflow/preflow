<?php

declare(strict_types=1);

namespace Preflow\View\Pdf;

/** An image a PdfImageSource found. */
final readonly class PdfImage
{
    /**
     * @param string  $file    the file to print; for an edited image, its rendered master
     * @param ?string $url     the image's public URL
     * @param bool    $private whether the image is access-controlled (needs allow_private: true to be printed)
     * @param bool    $isImage whether the stored file is an image at all
     */
    public function __construct(
        public string $file,
        public ?string $url,
        public bool $private,
        public bool $isImage,
    ) {
    }
}
