<?php

declare(strict_types=1);

namespace Preflow\Media\Image;

/** A HEIC/HEIF photo with more pixels than the converter decodes (found from its header, before any decoding). */
final class HeicTooLarge extends \RuntimeException
{
}
