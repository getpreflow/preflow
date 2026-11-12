<?php

declare(strict_types=1);

namespace Preflow\Blocks;

/** Invalid block declarations (raised when the registry is first built, or by preflow optimize). */
final class BlockConfigException extends \RuntimeException
{
}
