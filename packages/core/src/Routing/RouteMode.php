<?php

declare(strict_types=1);

namespace Preflow\Core\Routing;

enum RouteMode: string
{
    case Component = 'component';
    case Action = 'action';
}
