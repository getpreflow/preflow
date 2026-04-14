<?php

declare(strict_types=1);

namespace App\Providers;

use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;

final class AppServiceProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        // Register your bindings here
    }

    public function boot(Container $container): void
    {
        // Boot logic here

        // asset_url() is built into Preflow and already available in all templates.
        // Override it here if you need a CDN prefix or cache-busting version hash:
        //
        // $engine = $container->get(TemplateEngineInterface::class);
        // $engine->addFunction(new TemplateFunctionDefinition(
        //     name: 'asset_url',
        //     callable: fn (string $path) => 'https://cdn.example.com/' . ltrim($path, '/'),
        //     isSafe: true,
        // ));

        // Image URL helper for League Glide (or similar image processing pipelines).
        // Uncomment and adapt if your project serves resized/transformed images:
        //
        // $engine = $container->get(TemplateEngineInterface::class);
        // $engine->addFunction(new TemplateFunctionDefinition(
        //     name: 'img_url',
        //     callable: fn (string $path, string $preset = 'original') => '/img/' . $preset . '/' . ltrim($path, '/'),
        //     isSafe: true,
        // ));
    }
}
