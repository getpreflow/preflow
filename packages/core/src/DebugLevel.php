<?php

declare(strict_types=1);

namespace Preflow\Core;

enum DebugLevel: int
{
    case Off = 0;
    case On = 1;
    case Verbose = 2;

    public function isDebug(): bool
    {
        return $this !== self::Off;
    }
}
