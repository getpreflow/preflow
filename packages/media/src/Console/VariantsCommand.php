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
 * media:variants [--id=<id>] [--all]. With --all, the step widths that
 * are otherwise made on first request are made now as well. The package lists
 * it in composer.json (extra.preflow.commands), so a site needs no setup.
 */
final class VariantsCommand implements CommandInterface
{
    /** @param (\Closure(): Application)|null $appFactory returns a booted application (tests) */
    public function __construct(
        private readonly ?\Closure $appFactory = null,
    ) {}

    public function getName(): string { return 'media:variants'; }
    public function getDescription(): string { return 'Regenerate WebP/AVIF variants of library images [--id=<id>] [--all: also the step widths]'; }

    public function execute(array $args): int
    {
        if (ImageBackends::detect() === null) {
            echo "No GD or Imagick with WebP support: variants skipped.\n";
            return 0;
        }
        $id = null;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--id=')) {
                $id = substr($arg, 5);
            }
        }

        $app = $this->app();
        $library = $app->container()->get(MediaLibrary::class);
        $all = in_array('--all', $args, true);
        $allowed = $app->container()->get(ImageWidths::class)->allowed();
        $assets = $id !== null ? array_filter([$library->find($id)]) : $library->all();
        $count = 0;
        $made = 0;
        foreach ($assets as $asset) {
            if ($asset->kind() === 'image') {
                $asset = $library->regenerate($asset);
                if ($all) {
                    $made += $library->warm($asset, $allowed);
                }
                $count++;
            }
        }
        echo "Regenerated variants of {$count} image" . ($count === 1 ? '' : 's') . ".\n";
        if ($all) {
            echo "Made {$made} step-width variant" . ($made === 1 ? '' : 's') . " in advance.\n";
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
