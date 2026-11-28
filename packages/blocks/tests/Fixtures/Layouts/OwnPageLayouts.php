<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Fixtures\Layouts;

use Preflow\Blocks\Layouts\LayoutRegistry;
use Preflow\Blocks\Layouts\PageLayouts;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Data\DataManager;

/** A site's own PageLayouts, bound in register(): the blocks package keeps it (0.94). */
final class OwnPageLayouts extends ServiceProvider
{
    public static ?PageLayouts $built = null;

    public function register(Container $container): void
    {
        $container->singleton(PageLayouts::class, static fn (Container $c): PageLayouts => self::$built = new PageLayouts(
            $c->get(LayoutRegistry::class),
            $c->get(DataManager::class)->getTypeRegistry(),
        ));
    }
}
