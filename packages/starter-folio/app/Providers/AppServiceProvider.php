<?php

declare(strict_types=1);

namespace App\Providers;

use App\Seeds\SeedActor;
use App\Support\Markdown;
use Preflow\Core\Activity\ActivityActors;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\I18n\Translator;
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
        $engine = $container->get(TemplateEngineInterface::class);

        // markdown() renders a job description. It is safe to print because Markdown::render
        // escapes any HTML in the source.
        $engine->addFunction(new TemplateFunctionDefinition(
            name: 'markdown',
            callable: fn (?string $text): string => Markdown::render((string) $text),
            isSafe: true,
        ));

        // The seeders write "seed" where a person would be named: Folio's activity log shows
        // "Demo content" for it (in the admin's language), never "Former user".
        $container->get(ActivityActors::class)->register(SeedActor::MARKER, SeedActor::LABEL);
        if ($container->has(Translator::class)) {
            $container->get(Translator::class)->addDefaultsIfMissing('demo', dirname(__DIR__, 2) . '/resources/lang', 'admin');
        }
    }
}
