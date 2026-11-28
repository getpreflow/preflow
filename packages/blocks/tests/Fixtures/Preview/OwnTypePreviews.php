<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Fixtures\Preview;

use Preflow\Blocks\Preview\TypePreviews;
use Preflow\Components\ComponentMap;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Data\DataManager;

/** A site's own TypePreviews, bound in register(): the blocks package keeps it. */
final class OwnTypePreviews extends ServiceProvider
{
    public static ?TypePreviews $built = null;

    public function register(Container $container): void
    {
        $container->singleton(TypePreviews::class, static fn (Container $c): TypePreviews => self::$built = new TypePreviews(
            $c->get(DataManager::class)->getTypeRegistry(),
            $c->get(ComponentMap::class),
        ));
    }
}
