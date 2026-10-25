<?php

declare(strict_types=1);

namespace Preflow\Media\Console;

use Preflow\Core\Application;
use Preflow\DevTools\Command\CommandInterface;
use Preflow\Media\MediaLibrary;

/**
 * Read the colours (dominant colour, palette, text colour) of library images and of
 * documents with a preview: media:colors --all for every asset without colours, e.g.
 * those uploaded before colours existed (assets that have them are left alone);
 * --id=<id> reads that asset's again. The package lists it in composer.json
 * (extra.preflow.commands), so a site needs no setup.
 */
final class ColorsCommand implements CommandInterface
{
    /**
     * @param (\Closure(): Application)|null $appFactory returns a booted application (tests)
     * @param resource|null                  $stderr     error stream (default STDERR)
     */
    public function __construct(
        private readonly ?\Closure $appFactory = null,
        private readonly mixed $stderr = null,
    ) {}

    public function getName(): string { return 'media:colors'; }
    public function getDescription(): string { return 'Read the colours of library images and document previews [--all: every asset without colours] [--id=<id>: that one again]'; }

    public function execute(array $args): int
    {
        $id = null;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--id=')) {
                $id = substr($arg, 5);
            }
        }
        if ($id === null && !in_array('--all', $args, true)) {
            echo "Usage: media:colors --all (every asset without colours) | --id=<id> (that asset's colours again).\n";

            return 1;
        }

        $library = $this->app()->container()->get(MediaLibrary::class);
        $assets = $id !== null ? array_filter([$library->find($id)]) : $library->all();
        if ($id !== null && $assets === []) {
            fwrite($this->stderr ?? STDERR, "Asset {$id} not found.\n");

            return 1;
        }
        $made = 0;
        $failed = 0;
        foreach ($assets as $asset) {
            if (($asset->kind() !== 'image' && $asset->preview === null) || $asset->mime === 'image/svg+xml') {
                if ($id !== null) {
                    fwrite($this->stderr ?? STDERR, "Asset {$id} has no image to read colours from.\n");

                    return 1;
                }
                continue;
            }
            if ($id === null && $asset->color !== null) {
                continue;
            }
            $library->extractColors($asset) !== null ? $made++ : $failed++;
        }
        echo "Extracted the colours of {$made} asset" . ($made === 1 ? '' : 's') . ".\n";
        if ($failed > 0) {
            echo "{$failed} asset" . ($failed === 1 ? '' : 's') . " could not be read (unreadable, or it needs Imagick for its colour profile).\n";
        }

        return 0;
    }

    private function app(): Application
    {
        if ($this->appFactory !== null) {
            return ($this->appFactory)();
        }

        $app = Application::create(getcwd() ?: '.');
        $app->boot();

        return $app;
    }
}
