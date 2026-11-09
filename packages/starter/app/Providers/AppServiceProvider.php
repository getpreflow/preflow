<?php

declare(strict_types=1);

namespace App\Providers;

use Preflow\Core\Container\Container;
use App\Support\Markdown;
use Preflow\Core\Container\ServiceProvider;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;

final class AppServiceProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        // Bind your own services here.
    }

    public function boot(Container $container): void
    {
        // markdown() renders a job description. It is safe to print because Markdown::render
        // escapes any HTML in the source.
        $container->get(TemplateEngineInterface::class)->addFunction(new TemplateFunctionDefinition(
            name: 'markdown',
            callable: fn (?string $text): string => Markdown::render((string) $text),
            isSafe: true,
        ));
    }
}
