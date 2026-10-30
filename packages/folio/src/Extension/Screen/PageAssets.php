<?php

declare(strict_types=1);

namespace Preflow\Folio\Extension\Screen;

use Preflow\Folio\Extension\ExtensionAssets;
use Preflow\Folio\Extension\ExtensionException;
use Preflow\Folio\Http\AdminAssets;
use Preflow\Folio\Http\AdminAssetUrls;

/**
 * Turns the asset entries a screen lists (#[FolioScreen(assets:)] and ScreenView::$assets)
 * into versioned URLs for the admin layout: 'file.js' (own extension), '@folio/htmx.js',
 * '@folio/trix' or '@folio/ai-translate' (Folio's own files), '@other/file.css' (another enabled
 * extension).
 */
final class PageAssets
{
    /** @var array<string, list<string>> '@folio/<name>' => Folio asset files */
    private const FOLIO = [
        'htmx.js' => ['htmx.js'],
        'trix' => ['trix.css', 'trix.js'],
        // The AI review's styles and its DOM-free helpers (window.FolioAiTranslateCore), for a
        // screen that builds its own Translate button on the suggestion endpoints.
        'ai-translate' => ['ai-translate.css', 'ai-translate-core.js'],
    ];

    public function __construct(
        private readonly ExtensionAssets $ext,
        private readonly AdminAssets $folio,
        private readonly AdminAssetUrls $folioUrls,
    ) {}

    /** {prefix}/_assets/x/{extension}/ */
    public function base(string $extension): string
    {
        return $this->ext->base($extension);
    }

    /**
     * @param list<string> $entries
     * @return array{styles: list<string>, scripts: list<string>, modules: list<string>, htmx: bool}
     */
    public function resolve(string $extension, array $entries, string $owner): array
    {
        $out = ['styles' => [], 'scripts' => [], 'modules' => [], 'htmx' => false];
        foreach ($entries as $entry) {
            if (!is_string($entry) || $entry === '') {
                throw new ExtensionException("{$owner} lists an asset that is not a non-empty string.");
            }
            foreach ($this->urls($extension, $entry, $owner) as [$file, $url]) {
                if ($file === 'htmx.js' && $entry === '@folio/htmx.js') {
                    $out['htmx'] = true;
                }
                $bucket = match (true) {
                    str_ends_with($file, '.css') => 'styles',
                    str_ends_with($file, '.mjs') => 'modules',
                    str_ends_with($file, '.js') => 'scripts',
                    default => null, // other types are served but not printed
                };
                if ($bucket !== null && !in_array($url, $out[$bucket], true)) {
                    $out[$bucket][] = $url;
                }
            }
        }

        return $out;
    }

    /** @return list<array{string, string}> [file name, URL] */
    private function urls(string $extension, string $entry, string $owner): array
    {
        if (!str_starts_with($entry, '@')) {
            return [[$entry, $this->extensionUrl($extension, $entry, $owner, $entry)]];
        }
        $slash = strpos($entry, '/');
        if ($slash === false || $slash < 2 || $slash === strlen($entry) - 1) {
            throw new ExtensionException("{$owner} lists asset \"{$entry}\": use '@folio/<name>' or '@<extension>/<path>'.");
        }
        $name = substr($entry, 1, $slash - 1);
        $path = substr($entry, $slash + 1);
        if ($name === 'folio') {
            $files = self::FOLIO[$path] ?? null;
            if ($files === null) {
                throw new ExtensionException("{$owner} lists asset \"{$entry}\", which Folio does not offer (" . implode(', ', array_map(fn (string $k) => "@folio/{$k}", array_keys(self::FOLIO))) . ').');
            }
            $urls = [];
            foreach ($files as $file) {
                if ($this->folio->get($file) === null) {
                    throw new ExtensionException("{$owner} lists asset \"{$entry}\", but Folio has no \"{$file}\" registered.");
                }
                $urls[] = [$file, ($this->folioUrls)($file)];
            }

            return $urls;
        }

        return [[$path, $this->extensionUrl($name, $path, $owner, $entry)]];
    }

    private function extensionUrl(string $extension, string $path, string $owner, string $entry): string
    {
        $real = $this->ext->path($extension, $path);
        if ($real === null) {
            throw new ExtensionException("{$owner} lists asset \"{$entry}\", but \"{$path}\" is not a served file in the assets/ folder of enabled extension \"{$extension}\".");
        }

        return $this->ext->base($extension) . $path . '?v=' . ExtensionAssets::hash($real);
    }
}
