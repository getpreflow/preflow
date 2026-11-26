<?php

declare(strict_types=1);

namespace Preflow\Fields\Embed;

use Preflow\Fields\EmbedProvider;

/**
 * YouTube: watch pages (youtube.com/watch?v=…, also m. and music.), short links (youtu.be/…),
 * shorts, live and embed addresses (youtube.com and youtube-nocookie.com). The id is YouTube's
 * 11 characters. The player is youtube-nocookie.com's, which sets no cookie until the video plays.
 *
 * @since 0.91.0
 */
final class YouTube implements EmbedProvider
{
    private const string ID = '[A-Za-z0-9_-]{11}';

    private const array HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com'];

    public function name(): string
    {
        return 'youtube';
    }

    public function label(): string
    {
        return 'YouTube';
    }

    public function idOf(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return null;
        }
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        if ($host === 'youtu.be') {
            return preg_match('~^/(' . self::ID . ')/?\z~', $path, $m) === 1 ? $m[1] : null;
        }
        if (!in_array($host, self::HOSTS, true)) {
            return null;
        }
        if ($path === '/watch') {
            parse_str($parts['query'] ?? '', $query);
            $id = $query['v'] ?? null;

            return is_string($id) && preg_match('~^' . self::ID . '\z~', $id) === 1 ? $id : null;
        }

        return preg_match('~^/(?:shorts|embed|live|v)/(' . self::ID . ')/?\z~', $path, $m) === 1 ? $m[1] : null;
    }

    public function playerUrl(string $id): string
    {
        return 'https://www.youtube-nocookie.com/embed/' . rawurlencode($id) . '?autoplay=1';
    }
}
