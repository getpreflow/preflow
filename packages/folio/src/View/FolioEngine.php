<?php

declare(strict_types=1);

namespace Preflow\Folio\View;

use Preflow\Twig\TwigEngine;
use Preflow\View\LazyTemplateEngine;
use Preflow\View\SharingTemplateEngine;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;

/**
 * The engine Folio renders with. Folio's own templates are Twig (every name ending in .twig: the
 * admin, the fallback frontend templates such as @folio/frontend/page.twig, extensions'
 * @{name}/… templates and their overrides in resources/folio): they render through a Twig engine
 * Folio owns, whatever app.engine is. Any other name is an app template (a type's frontend
 * "template" written for the app's engine) and renders through the app's engine.
 *
 * On a Twig app both are the app's engine itself, so nothing changes there. Otherwise Folio's Twig
 * engine is built on its first render and gets every function and global of the app's engine
 * (SharingTemplateEngine::share()), Folio's folio_* functions included; Folio's namespaces are
 * registered on it only.
 */
final class FolioEngine implements TemplateEngineInterface
{
    private function __construct(
        private readonly TemplateEngineInterface $app,
        private readonly TemplateEngineInterface $twig,
    ) {}

    /** @param \Closure(): TemplateEngineInterface $makeTwig builds Folio's own Twig engine (only for a non-Twig app) */
    public static function for(TemplateEngineInterface $app, \Closure $makeTwig): self
    {
        if ($app instanceof TwigEngine) {
            return new self($app, $app);
        }
        $twig = new LazyTemplateEngine($makeTwig, 'twig');
        if ($app instanceof SharingTemplateEngine) {
            $app->share($twig);
        }

        return new self($app, $twig);
    }

    /** The app's engine. */
    public function app(): TemplateEngineInterface
    {
        return $this->app;
    }

    /** The engine of Folio's Twig templates (the app's on a Twig app). */
    public function twig(): TemplateEngineInterface
    {
        return $this->twig;
    }

    public function render(string $template, array $context = []): string
    {
        return $this->engineFor($template)->render($template, $context);
    }

    public function exists(string $template): bool
    {
        return $this->engineFor($template)->exists($template);
    }

    /** On the app's engine, so app templates can call it; a sharing app engine passes it on to Folio's. */
    public function addFunction(TemplateFunctionDefinition $function): void
    {
        $this->app->addFunction($function);
        if ($this->separate() && !$this->app instanceof SharingTemplateEngine) {
            $this->twig->addFunction($function);
        }
    }

    public function addGlobal(string $name, mixed $value): void
    {
        $this->app->addGlobal($name, $value);
        if ($this->separate() && !$this->app instanceof SharingTemplateEngine) {
            $this->twig->addGlobal($name, $value);
        }
    }

    public function getTemplateExtension(): string
    {
        return $this->app->getTemplateExtension();
    }

    /** Folio's namespaces hold Twig templates: on Folio's Twig engine (the app's on a Twig app). */
    public function addNamespace(string $namespace, string $path): void
    {
        $this->twig->addNamespace($namespace, $path);
    }

    private function engineFor(string $template): TemplateEngineInterface
    {
        return str_ends_with($template, '.twig') ? $this->twig : $this->app;
    }

    private function separate(): bool
    {
        return $this->twig !== $this->app;
    }
}
