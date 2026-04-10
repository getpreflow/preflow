<?php

declare(strict_types=1);

namespace Preflow\Htmx;

enum SwapStrategy: string
{
    case OuterHTML = 'outerHTML';
    case InnerHTML = 'innerHTML';
    case BeforeBegin = 'beforebegin';
    case AfterBegin = 'afterbegin';
    case BeforeEnd = 'beforeend';
    case AfterEnd = 'afterend';
    case Delete = 'delete';
    case None = 'none';
}
