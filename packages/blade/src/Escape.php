<?php

declare(strict_types=1);

namespace Preflow\Blade;

use Preflow\View\SafeHtml;

/** What a Blade `{{ }}` prints: SafeHtml as it is, anything else escaped as Blade's e() does. */
final class Escape
{
    public static function print(mixed $value): string
    {
        return $value instanceof SafeHtml ? $value->html : e($value);
    }
}
