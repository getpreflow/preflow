<?php

declare(strict_types=1);

namespace Preflow\Folio\Console;

use Preflow\DevTools\Command\CommandInterface;
use Preflow\Folio\Assets\AssetLibrary;
use Preflow\Folio\Assets\Image\ImageBackends;

/** Rebuild image variants, e.g. after changing the configured widths: folio:assets:variants [--id=<id>] */
final class AssetsVariantsCommand implements CommandInterface
{
    use BootsApplication;

    public function getName(): string { return 'folio:assets:variants'; }
    public function getDescription(): string { return 'Regenerate WebP/AVIF variants of library images'; }

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

        $library = $this->app()->container()->get(AssetLibrary::class);
        $assets = $id !== null ? array_filter([$library->find($id)]) : $library->all();
        $count = 0;
        foreach ($assets as $asset) {
            if ($asset->kind() === 'image') {
                $library->regenerate($asset);
                $count++;
            }
        }
        echo "Regenerated variants of {$count} image" . ($count === 1 ? '' : 's') . ".\n";

        return 0;
    }
}
