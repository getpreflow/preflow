<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Fixtures;

use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Twig\TwigEngine;
use Preflow\View\AssetCollector;
use Preflow\View\NonceGenerator;
use Preflow\View\TemplateEngineInterface;

/** Binds its own template engine after core booted notify, as a package with an engine of its own would. */
final class MailEngineProvider extends ServiceProvider
{
    public static ?TemplateEngineInterface $engine = null;

    public function register(Container $container): void
    {
        self::$engine = new TwigEngine([], new AssetCollector(new NonceGenerator()));
        $container->instance(TemplateEngineInterface::class, self::$engine);
    }
}
