<?php

declare(strict_types=1);

namespace Preflow\Fields\Tests\Support;

use Preflow\Fields\EmbedProvider;

/** A video host a site adds: addresses https://video.example.org/w/{id}. */
final class ExampleVideoProvider implements EmbedProvider
{
    public function name(): string
    {
        return 'example';
    }

    public function label(): string
    {
        return 'Example Video';
    }

    public function idOf(string $url): ?string
    {
        return preg_match('~^https://video\.example\.org/w/([A-Za-z0-9]+)$~', $url, $m) === 1 ? $m[1] : null;
    }

    public function playerUrl(string $id): string
    {
        return 'https://video.example.org/videos/embed/' . rawurlencode($id);
    }
}
