<?php

declare(strict_types=1);

namespace Preflow\Blocks\Layouts;

/** Invalid layout declarations (raised when the registry is first built, or by preflow optimize). */
final class LayoutConfigException extends \RuntimeException
{
}
