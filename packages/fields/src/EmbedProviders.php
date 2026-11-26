<?php

declare(strict_types=1);

namespace Preflow\Fields;

use Preflow\Fields\Embed\Vimeo;
use Preflow\Fields\Embed\YouTube;

/**
 * The video hosts the site knows: YouTube and Vimeo, offered by every embed field, and those the
 * site adds (add()), offered by a field that lists them in its "providers" option.
 *
 * FieldsServiceProvider binds one for the app; a site adds its own hosts to it in a service
 * provider's boot(). The field's check, the editor and the template functions all ask this one,
 * so a host is added in one place.
 *
 * @since 0.91.0
 */
final class EmbedProviders
{
    /** The providers every embed field offers. */
    public const array BUILT_IN = ['youtube', 'vimeo'];

    /** @var array<string, EmbedProvider> */
    private array $providers = [];

    public function __construct(EmbedProvider ...$added)
    {
        $this->providers = ['youtube' => new YouTube(), 'vimeo' => new Vimeo()];
        foreach ($added as $provider) {
            $this->add($provider);
        }
    }

    /**
     * Adds a host the site's embed fields may list in "providers".
     *
     * @throws \LogicException for a name that is taken or not a provider name
     */
    public function add(EmbedProvider $provider): void
    {
        $name = $provider->name();
        if (preg_match('/^[a-z0-9_-]+$/', $name) !== 1) {
            throw new \LogicException('An EmbedProvider\'s name is lowercase letters, digits, - and _; "' . $name . '" is not.');
        }
        if (isset($this->providers[$name])) {
            throw new \LogicException('An EmbedProvider named "' . $name . '" is already registered' . (in_array($name, self::BUILT_IN, true) ? ' (built in)' : '') . '; give yours another name.');
        }
        $this->providers[$name] = $provider;
    }

    public function get(string $name): ?EmbedProvider
    {
        return $this->providers[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->providers[$name]);
    }

    /**
     * The providers a field offers: the built-in ones and those of $listed (its "providers").
     *
     * @param list<string> $listed
     * @return list<EmbedProvider>
     * @throws \LogicException for a listed name no provider has (the site forgot to add it)
     */
    public function offered(array $listed = []): array
    {
        $offered = [];
        foreach ([...self::BUILT_IN, ...$listed] as $name) {
            $offered[$name] ??= $this->providers[$name] ?? throw new \LogicException('The embed field lists the provider "' . $name . '", but no EmbedProvider of that name is registered: add it with EmbedProviders::add() in a service provider, or remove it from "providers".');
        }

        return array_values($offered);
    }

    /**
     * The provider and video id of an address, among the providers $listed offers (see offered()).
     *
     * @param list<string> $listed
     * @return array{EmbedProvider, string}|null
     */
    public function recognize(string $url, array $listed = []): ?array
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        foreach ($this->offered($listed) as $provider) {
            $id = $provider->idOf($url);
            if ($id !== null && $id !== '') {
                return [$provider, $id];
            }
        }

        return null;
    }

    /**
     * Every registered provider, for reading a plain address whose field is not known (a stored
     * text value).
     *
     * @return list<string> the names of the providers the site added
     */
    public function added(): array
    {
        return array_values(array_diff(array_keys($this->providers), self::BUILT_IN));
    }
}
