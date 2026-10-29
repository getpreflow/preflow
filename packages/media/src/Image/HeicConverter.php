<?php

declare(strict_types=1);

namespace Preflow\Media\Image;

/**
 * Turns a HEIC or HEIF photo (iPhones and many Android phones) into a JPEG. The media
 * library stores the JPEG as the asset's file; HEIC itself is never stored.
 */
interface HeicConverter
{
    /** Whether this server can decode HEIC and HEIF. */
    public function available(): bool;

    /**
     * The photo as JPEG bytes, upright (orientation 1), with its colour profile and EXIF.
     *
     * @throws \RuntimeException when the file cannot be decoded
     */
    public function toJpeg(string $bytes): string;
}
