<?php

declare(strict_types=1);

namespace Preflow\Media\Console;

use Preflow\Core\Application;
use Preflow\DevTools\Command\CommandInterface;
use Preflow\Media\Image\ImageBackends;
use Preflow\Media\Image\ImageWidths;
use Preflow\Media\MediaLibrary;

/**
 * Rebuild image variants, e.g. after changing the configured widths:
 * media:variants [--id=<id>] [--all] [--limit=<n> [--offset=<n>]]. With --all, the
 * step widths and box preset files that are otherwise made on first request are made now as well. --limit
 * does at most n images (newest first) and prints the command that continues. The
 * package lists it in composer.json (extra.preflow.commands), so a site needs no setup.
 */
final class VariantsCommand implements CommandInterface
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

    public function getName(): string { return 'media:variants'; }
    public function getDescription(): string { return 'Regenerate WebP/AVIF variants of library images [--id=<id>] [--all: also the step widths and box presets] [--limit=<n> --offset=<n>: in chunks]'; }

    public function execute(array $args): int
    {
        if (ImageBackends::detect() === null) {
            echo "No GD or Imagick with WebP support: variants skipped.\n";
            return 0;
        }
        $id = self::option($args, 'id');
        try {
            $limit = self::number($args, 'limit', 1);
            $offset = self::number($args, 'offset', 0) ?? 0;
        } catch (\InvalidArgumentException $e) {
            fwrite($this->stderr ?? STDERR, $e->getMessage());

            return 1;
        }

        $app = $this->app();
        $library = $app->container()->get(MediaLibrary::class);
        $all = in_array('--all', $args, true);
        $allowed = $app->container()->get(ImageWidths::class)->allowed();
        $assets = $id !== null ? array_filter([$library->find($id)]) : $library->all();
        $images = array_values(array_filter($assets, static fn ($a) => $a->kind() === 'image'));
        $chunk = array_slice($images, $offset, $limit);
        $left = max(0, count($images) - $offset - count($chunk));
        $made = 0;
        foreach ($chunk as $asset) {
            $asset = $library->regenerate($asset);
            if ($all) {
                $made += $library->warm($asset, $allowed);
            }
        }
        echo 'Regenerated variants of ' . self::plural(count($chunk), 'image') . ".\n";
        if ($all) {
            echo 'Made ' . self::plural($made, 'step-width and box variant') . " in advance.\n";
        }
        if ($left > 0) {
            echo self::plural($left, 'image') . ' left: run again with ' . ($all ? '--all ' : '') . "--limit={$limit} --offset=" . ($offset + count($chunk)) . ".\n";
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
