<?php

declare(strict_types=1);

namespace Preflow\Fields\Rules;

use Preflow\Fields\EmbedOptions;
use Preflow\Fields\EmbedProvider;
use Preflow\Fields\EmbedProviders;
use Preflow\Fields\Values\Link;
use Preflow\Validation\Message;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

/**
 * An embed field's value (Embed's stored object, JSON text of it, or a plain address) is a video
 * of a provider the field offers (YouTube, Vimeo, and those of its "providers"); a stored provider
 * and id are the ones its address gives (so the page never plays another video than the address
 * says); a poster is an asset id. An address of another host is refused naming the host and the
 * providers to use instead. An empty value passes ("required" decides).
 *
 * Registered for every "embed" field (FieldKinds::rules()).
 *
 * @since 0.91.0
 */
final class ValidEmbed implements ValidationRule
{
    public function __construct(
        private readonly EmbedOptions $options,
        private readonly EmbedProviders $providers,
    ) {}

    /** @throws \LogicException when the field lists a provider the site never added */
    public function validate(mixed $value, ValidationContext $context): true|Message
    {
        if ($value === null || $value === '' || $value === []) {
            return true;
        }
        $offered = $this->providers->offered($this->options->providers);
        $names = implode(', ', array_map(static fn (EmbedProvider $p): string => $p->label(), $offered));
        if (is_string($value) && !str_starts_with(ltrim($value), '{')) {
            $data = ['url' => $value];
        } else {
            $data = Link::decode($value);
            if ($data === null) {
                return new Message('validation.embed', ['providers' => $names]);
            }
        }
        $url = is_string($data['url'] ?? null) ? trim($data['url']) : '';
        $poster = $data['poster'] ?? null;
        if ($url === '' && ($poster === null || $poster === '') && ($data['provider'] ?? null) === null && ($data['id'] ?? null) === null) {
            return true; // nothing filled in
        }
        $found = $url === '' ? null : $this->providers->recognize($url, $this->options->providers);
        if ($found === null) {
            $host = (string) parse_url($url, PHP_URL_HOST);
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

            return $host !== '' && in_array($scheme, ['http', 'https'], true)
                ? new Message('validation.embed_provider', ['host' => $host, 'providers' => $names])
                : new Message('validation.embed', ['providers' => $names]);
        }
        foreach (['provider' => $found[0]->name(), 'id' => $found[1]] as $key => $expected) {
            if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== $expected) {
                return new Message('validation.embed', ['providers' => $names]);
            }
        }
        if ($poster !== null && $poster !== '' && (!is_string($poster) || trim($poster) === '')) {
            return new Message('validation.embed_poster');
        }

        return true;
    }
}
