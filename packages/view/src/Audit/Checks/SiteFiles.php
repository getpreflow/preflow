<?php

declare(strict_types=1);

namespace Preflow\View\Audit\Checks;

use Preflow\View\Audit\PageRenderer;

/**
 * The site checks' way to a file such as robots.txt: the web server serves a
 * file in public/ without asking the app, else the app answers it.
 *
 * @internal
 * @since 0.54
 */
final class SiteFiles
{
    /** The file in public/ that the web server serves for $path (as written in the URL); null when there is none. */
    public static function publicFile(?string $publicDir, string $path): ?string
    {
        $path = rawurldecode($path);
        if ($publicDir === null || $path === '/' || $path === '' || str_contains($path, '..')) {
            return null;
        }
        $file = rtrim($publicDir, '/') . $path;

        return is_file($file) ? $file : null;
    }

    /** What the site serves at $path: the public file's contents, else the app's 200 answer; null when neither. */
    public static function read(PageRenderer $renderer, ?string $publicDir, string $path): ?string
    {
        $file = self::publicFile($publicDir, $path);
        if ($file !== null) {
            return (string) file_get_contents($file);
        }
        $page = $renderer->render($path);

        return $page->status === 200 ? $page->body : null;
    }
}
