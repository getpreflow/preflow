<?php

declare(strict_types=1);

namespace Preflow\Fields\Embed;

use Preflow\Fields\EmbedProvider;

/**
 * Vimeo: video pages (vimeo.com/76979871, also under channels/…, groups/…/videos/…,
 * showcase/…/video/…) and player addresses (player.vimeo.com/video/76979871). A video shared with
 * a private link carries its hash (vimeo.com/76979871/8272103f6e, or ?h=8272103f6e): the id is then
 * "76979871:8272103f6e". The player is asked not to track (dnt=1).
 *
 * @since 0.91.0
 */
final class Vimeo implements EmbedProvider
{
    public function name(): string
    {
        return 'vimeo';
    }

    public function label(): string
    {
        return 'Vimeo';
    }

    public function idOf(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return null;
        }
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        parse_str($parts['query'] ?? '', $query);
        $hash = is_string($query['h'] ?? null) && preg_match('~^[0-9a-f]+$~', $query['h']) === 1 ? $query['h'] : null;
        if ($host === 'player.vimeo.com') {
            return preg_match('~^/video/(\d+)/?$~', $path, $m) === 1 ? $m[1] . ($hash !== null ? ':' . $hash : '') : null;
        }
        if (!in_array($host, ['vimeo.com', 'www.vimeo.com'], true)) {
            return null;
        }
        if (preg_match('~^/(\d+)(?:/([0-9a-f]+))?/?$~', $path, $m) === 1) {
            $hash = ($m[2] ?? '') !== '' ? $m[2] : $hash;

            return $m[1] . ($hash !== null ? ':' . $hash : '');
        }

        return preg_match('~^/(?:channels/[^/]+|groups/[^/]+/videos|showcase/[^/]+/video)/(\d+)/?$~', $path, $m) === 1 ? $m[1] : null;
    }

    public function playerUrl(string $id): string
    {
        [$video, $hash] = array_pad(explode(':', $id, 2), 2, null);

        return 'https://player.vimeo.com/video/' . rawurlencode((string) $video) . '?dnt=1&autoplay=1' . ($hash !== null ? '&h=' . rawurlencode($hash) : '');
    }
}
