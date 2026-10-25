<?php

declare(strict_types=1);

namespace Preflow\Media\Tests\Support;

use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Media\Access\PrivateFileAccess;

/** A site's own private-file access: everyone may download (listed after MediaServiceProvider). */
final class AllowPrivateFiles extends ServiceProvider
{
    public function register(Container $container): void
    {
        $container->instance(PrivateFileAccess::class, new class implements PrivateFileAccess {
            public function decide(\Psr\Http\Message\ServerRequestInterface $request, \Preflow\Media\Asset $asset): ?\Psr\Http\Message\ResponseInterface
            {
                return null;
            }
        });
    }
}
