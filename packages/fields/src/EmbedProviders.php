<?php

declare(strict_types=1);

namespace Preflow\Fields;

use Preflow\Fields\Embed\Vimeo;
use Preflow\Fields\Embed\YouTube;

/**
 * The video hosts the site knows: YouTube and Vimeo, offered by every embed field, and those the
 * site adds (add()), offered by a field that lists them in its "providers" option.
 *
 * FieldsServiceProvider binds one for the app. A site adds its own hosts by binding its own in a
 * service provider's register() (`new EmbedProviders(new PeerTube())`), so they are known when
 * the content types are read and a field listing an unknown name is refused at boot. The
 * field's check, the editor and the template functions all ask this one.
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
        if (preg_match('/^[a-z0-9_-]+\z/', $name) !== 1) {
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
     * The providers a field offers: the built-in ones and those of $listed (its "providers") that
     * are registered. A listed name no provider has is left out here; missing() names it (the
     * boot check refuses it, and ValidEmbed says so on save).
     *
     * @param list<string> $listed
     * @return list<EmbedProvider>
     */
    public function offered(array $listed = []): array
    {
        $offered = [];
        foreach ([...self::BUILT_IN, ...$listed] as $name) {
            if (isset($this->providers[$name])) {
                $offered[$name] = $this->providers[$name];
            }
        }

        return array_values($offered);
    }

    /**
     * The names of $listed no provider is registered for.
     *
     * @param list<string> $listed
     * @return list<string>
     */
    public function missing(array $listed): array
    {
        return array_values(array_filter($listed, fn (string $name): bool => !isset($this->providers[$name])));
    }

    /**
     * A closure that gives the built-in providers (YouTube, Vimeo), made on its first call and
     * kept by the closure: the default where no app registry is given.
     *
     * @return \Closure(): self
     */
    public static function lazyBuiltIn(): \Closure
    {
        $providers = null;

        return static function () use (&$providers): self {
            return $providers ??= new self();
        };
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
