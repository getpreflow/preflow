<?php

declare(strict_types=1);

namespace Preflow\Media\Derived;

use Preflow\Media\Asset;

/**
 * Makes a copy's file from its original (a watermark, a cut-out, …), so the library can
 * make the copy again when the original changes (MediaLibrary::remake()). Registered on
 * the shared DerivedMakers under its name, which a copy stores as its maker.
 */
interface DerivedMaker
{
    /** The name a copy stores as its maker ("copy" is the library's own and cannot be taken). */
    public function name(): string;

    /**
     * Make the copy's file.
     *
     * @param string $file the original as people see it: turned and mirrored, without the crop,
     *                     at full size (the raw file for an unedited or non-image original)
     * @param array<string, mixed> $params the copy's settings (e.g. a copyright line)
     * @return string the absolute path of a new temp file; the library owns it and deletes it after use
     */
    public function make(Asset $original, string $file, array $params): string;
}
