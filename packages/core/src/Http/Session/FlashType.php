<?php

declare(strict_types=1);

namespace Preflow\Core\Http\Session;

enum FlashType: string
{
    case Success = 'success';
    case Error = 'error';
    case Info = 'info';
    case Warning = 'warning';
}
