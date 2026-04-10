<?php
declare(strict_types=1);
namespace Preflow\View;

enum JsPosition: string
{
    case Head = 'head';
    case Body = 'body';
    case Inline = 'inline';
}
