<?php

declare(strict_types=1);

namespace Preflow\Fields\Values;

use Preflow\Fields\EmbedProviders;

/**
 * The value of an embed field: a video of a provider (YouTube, Vimeo, or a host the site added),
 * the address as the editor pasted it, and an optional poster (the id of an image of the media
 * library, shown before the video plays).
 *
 * Stored as an object with all four keys (toStored()):
 *
 *     {"provider": "youtube", "id": "aqz-KE-bpKQ", "url": "https://youtu.be/aqz-KE-bpKQ", "poster": "a0f3"}
 *
 * A JSON file holds the object, SQL storage JSON text of it; fromStored() reads both, and a plain
 * address too (what a seed or the REST API may write). The player address is never stored: it
 * comes from the provider (playerUrl()), so a provider's change of player is one code change.
 *
 * @since 0.91.0
 */
final readonly class Embed
{
    public function __construct(
        public string $provider,
        public string $id,
        public string $url,
        public ?string $poster = null,
    ) {
        if ($provider === '' || $id === '' || trim($url) === '') {
            throw new \InvalidArgumentException('An embed needs its provider, the video id and the address; use null for no video.');
        }
    }

    /**
     * The video at $url among the providers a field offers (the built-in ones and $listed), with
     * an optional poster; null when the address is no video of one of them.
     *
     * @param list<string> $listed the field's "providers"
     */
    public static function fromUrl(string $url, EmbedProviders $providers, array $listed = [], ?string $poster = null): ?self
    {
        $found = $providers->recognize($url, $listed);

        return $found === null ? null : new self($found[0]->name(), $found[1], trim($url), $poster === '' ? null : $poster);
    }

    /**
     * The video a stored value holds: the object toStored() writes, JSON text of it, or a plain
     * address (read with every provider of $providers, the built-in ones without it). Null for an
     * empty value and one that is no video; reading never fails.
     */
    public static function fromStored(mixed $stored, ?EmbedProviders $providers = null): ?self
    {
        if (is_string($stored) && !str_starts_with(ltrim($stored), '{')) {
            $providers ??= new EmbedProviders();

            return trim($stored) === '' ? null : self::fromUrl($stored, $providers, $providers->added());
        }
        $data = Link::decode($stored);
        if ($data === null) {
            return null;
        }
        $text = static fn (string $key): ?string => is_string($data[$key] ?? null) && trim($data[$key]) !== '' ? trim($data[$key]) : null;
        $provider = $text('provider');
        $id = $text('id');
        $url = $text('url');
        if ($provider === null || $id === null || $url === null) {
            return null;
        }

        return new self($provider, $id, $url, $text('poster'));
    }

    /** @return array{provider: string, id: string, url: string, poster: ?string} */
    public function toStored(): array
    {
        return ['provider' => $this->provider, 'id' => $this->id, 'url' => $this->url, 'poster' => $this->poster];
    }

    /** The player's address (the iframe's src after the click); null when the provider is not registered (any more). */
    public function playerUrl(EmbedProviders $providers): ?string
    {
        return $providers->get($this->provider)?->playerUrl($this->id);
    }
}
