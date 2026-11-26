<?php

declare(strict_types=1);

namespace Preflow\Fields;

use Preflow\Fields\Values\Embed;
use Preflow\View\AssetCollector;
use Preflow\View\JsPosition;

/**
 * An embed field's value on a page: the player's HTML (html(), what embed_player() prints and
 * Folio's frontend output uses) and the parts for a template's own markup (data(), embed_data()).
 *
 * html() asks the bound EmbedPlayer for the markup and adds the player's script and layout rules
 * (assets/embed-player.js and .css) to the page's assets, once per page: they print with the
 * page's CSP nonce like every collected asset. The poster's address comes from the bound
 * RecordAddresses when it also is an AssetAddresses (Folio's is); without one, no poster shows.
 *
 * @since 0.91.0
 */
final class EmbedOutput
{
    /** @var array<string, string> the asset files' contents, read once per instance */
    private array $files = [];

    /**
     * @param \Closure(): EmbedProviders $providers
     * @param \Closure(): EmbedPlayer $player
     * @param \Closure(): ?RecordAddresses $addresses
     * @param \Closure(): ?AssetCollector $assets
     */
    public function __construct(
        private readonly \Closure $providers,
        private readonly \Closure $player,
        private readonly \Closure $addresses,
        private readonly \Closure $assets,
    ) {}

    /** The player for $value in $locale, with the page's assets added; '' for no video or a provider no longer registered. */
    public function html(mixed $value, ?string $title, string $locale): string
    {
        $view = $this->view($value, $title, $locale);
        if ($view === null) {
            return '';
        }
        $assets = ($this->assets)();
        if ($assets !== null) {
            $assets->addCss($this->file('embed-player.css'), 'preflow-embed');
            $assets->addJs($this->file('embed-player.js'), JsPosition::Body, 'preflow-embed');
        }

        return ($this->player)()->html($view);
    }

    /**
     * The parts of $value for a template's own markup: provider, id, url (as pasted), poster (the
     * asset id), player (the iframe's address, to load after a click), label (the provider's name
     * for people) and poster_url; null for no video.
     *
     * @return array{provider: string, id: string, url: string, poster: ?string, player: string, label: string, poster_url: ?string}|null
     */
    public function data(mixed $value): ?array
    {
        $view = $this->view($value, null, 'en');

        return $view === null ? null : $view->embed->toStored() + ['player' => $view->playerUrl, 'label' => $view->providerLabel, 'poster_url' => $view->posterUrl];
    }

    private function view(mixed $value, ?string $title, string $locale): ?EmbedView
    {
        $providers = ($this->providers)();
        $embed = Embed::fromStored($value, $providers);
        $provider = $embed !== null ? $providers->get($embed->provider) : null;
        if ($embed === null || $provider === null) {
            return null;
        }
        $addresses = ($this->addresses)();
        $poster = $embed->poster !== null && $addresses instanceof AssetAddresses ? $addresses->assetAddressOf($embed->poster) : null;

        return new EmbedView($embed, $provider->label(), $provider->playerUrl($embed->id), $poster, $title, $locale);
    }

    private function file(string $name): string
    {
        return $this->files[$name] ??= (string) file_get_contents(__DIR__ . '/../assets/' . $name);
    }
}
