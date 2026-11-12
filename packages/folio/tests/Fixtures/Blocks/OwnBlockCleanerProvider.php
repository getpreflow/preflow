<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Blocks;

use Preflow\Blocks\BlockTextCleaner;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;

/** An app's own BlockTextCleaner, bound in register() before Folio's provider runs. */
final class OwnBlockCleanerProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        $container->singleton(BlockTextCleaner::class, static fn (): BlockTextCleaner => new class implements BlockTextCleaner {
            public function block(array $block): array
            {
                return $block;
            }
        });
    }
}
