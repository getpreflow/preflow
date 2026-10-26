<?php

declare(strict_types=1);

namespace Preflow\Media\Console;

use Preflow\Core\Application;
use Preflow\DevTools\Command\CommandInterface;
use Preflow\Media\MediaLibrary;

/**
 * Read the colours (dominant colour, palette, text colour) of library images and of
 * documents with a preview: media:colors --all for every asset without colours, e.g.
 * those uploaded before colours existed (assets that have them are left alone, so a
 * run that was stopped resumes where it ended); --limit=<n> does at most n of them and
 * prints the command that continues (--offset=<n> skips the first n, e.g. ones that
 * could not be read); --id=<id> reads that asset's again. The package lists it in
 * composer.json (extra.preflow.commands), so a site needs no setup.
 */
final class ColorsCommand implements CommandInterface
{
    use ChunkOptions;

    /**
     * @param (\Closure(): Application)|null $appFactory returns a booted application (tests)
     * @param resource|null                  $stderr     error stream (default STDERR)
     */
    public function __construct(
        private readonly ?\Closure $appFactory = null,
        private readonly mixed $stderr = null,
    ) {}

    public function getName(): string { return 'media:colors'; }
    public function getDescription(): string { return 'Read the colours of library images and document previews [--all: every asset without colours] [--limit=<n> --offset=<n>: in chunks] [--id=<id>: that one again]'; }

    public function execute(array $args): int
    {
        $id = self::option($args, 'id');
        try {
            $limit = self::number($args, 'limit', 1);
            $offset = self::number($args, 'offset', 0) ?? 0;
        } catch (\InvalidArgumentException $e) {
            fwrite($this->stderr ?? STDERR, $e->getMessage());

            return 1;
        }
        if ($id === null && !in_array('--all', $args, true)) {
            echo "Usage: media:colors --all [--limit=<n>] [--offset=<n>] (every asset without colours) | --id=<id> (that asset's colours again).\n";

            return 1;
        }

        $library = $this->app()->container()->get(MediaLibrary::class);
        $assets = $id !== null ? array_filter([$library->find($id)]) : $library->all();
        if ($id !== null && $assets === []) {
            fwrite($this->stderr ?? STDERR, "Asset {$id} not found.\n");

            return 1;
        }
        $todo = [];
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
            $todo[] = $asset;
        }
        $chunk = array_slice($todo, $offset, $limit);
        $left = max(0, count($todo) - $offset - count($chunk));
        $made = 0;
        $failed = 0;
        foreach ($chunk as $asset) {
            $library->extractColors($asset) !== null ? $made++ : $failed++;
        }
        echo 'Extracted the colours of ' . self::plural($made, 'asset') . ".\n";
        if ($failed > 0) {
            echo self::plural($failed, 'asset') . " could not be read (unreadable, or it needs Imagick for its colour profile).\n";
        }
        if ($left > 0) {
            // Assets that got colours drop out of the list; only failures stay ahead.
            $next = $offset + $failed;
            echo self::plural($left, 'asset') . " left: run again with --all --limit={$limit}" . ($next > 0 ? " --offset={$next}" : '') . ".\n";
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
