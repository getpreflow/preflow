<?php

declare(strict_types=1);

namespace App\Support;

use League\CommonMark\CommonMarkConverter;

/**
 * Job descriptions are stored as Markdown and rendered here. Raw HTML in the source is escaped
 * and links with unsafe schemes (javascript:) lose their href, so an employer can't inject script.
 */
final class Markdown
{
    private static ?CommonMarkConverter $converter = null;

    public static function render(string $text): string
    {
        self::$converter ??= new CommonMarkConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        return trim(self::$converter->convert($text)->getContent());
    }
}
