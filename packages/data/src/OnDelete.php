<?php

declare(strict_types=1);

namespace Preflow\Data;

enum OnDelete: string
{
    case Detach = 'detach';
    case Cascade = 'cascade';
    case Restrict = 'restrict';
}
