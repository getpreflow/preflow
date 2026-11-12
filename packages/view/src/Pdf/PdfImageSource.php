<?php

declare(strict_types=1);

namespace Preflow\View\Pdf;

/**
 * Where pdf_image() looks an image up by id. view defines the question;
 * whatever manages images (preflow/media) binds the answer in the container.
 */
interface PdfImageSource
{
    /** The image stored under $id, or null when there is none. */
    public function find(string $id): ?PdfImage;
}
