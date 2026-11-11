<?php

declare(strict_types=1);

namespace Preflow\View;

/**
 * The app's engine when it is not Twig (Blade, or a TemplateEngineFactory's): it renders and
 * registers as the engine it wraps, and keeps every function and global the packages and the app
 * register, so another engine can share() them. Folio renders its own Twig templates with such an
 * engine, which then has csrf_field(), t(), hd and the rest just like the app's. Namespaces are the
 * app engine's own and are not shared.
 */
final class SharingTemplateEngine implements TemplateEngineInterface
{
    /** @var list<\Closure(TemplateEngineInterface): void> every function and global so far, in order */
    private array $shared = [];

    /** @var list<TemplateEngineInterface> */
    private array $sharers = [];

    public function __construct(
        private readonly TemplateEngineInterface $engine,
    ) {}

    /** The engine it wraps. */
    public function engine(): TemplateEngineInterface
    {
        return $this->engine;
    }

    /** Gives $engine every function and global registered so far, and each one registered from now on. */
    public function share(TemplateEngineInterface $engine): void
    {
        foreach ($this->shared as $apply) {
            $apply($engine);
        }
        $this->sharers[] = $engine;
    }

    public function render(string $template, array $context = []): string
    {
        return $this->engine->render($template, $context);
    }

    public function exists(string $template): bool
    {
        return $this->engine->exists($template);
    }

    public function addFunction(TemplateFunctionDefinition $function): void
    {
        $this->engine->addFunction($function);
        $this->keep(static fn (TemplateEngineInterface $engine) => $engine->addFunction($function));
    }

    public function addGlobal(string $name, mixed $value): void
    {
        $this->engine->addGlobal($name, $value);
        $this->keep(static fn (TemplateEngineInterface $engine) => $engine->addGlobal($name, $value));
    }

    public function getTemplateExtension(): string
    {
        return $this->engine->getTemplateExtension();
    }

    public function addNamespace(string $namespace, string $path): void
    {
        $this->engine->addNamespace($namespace, $path);
    }

    /** @param \Closure(TemplateEngineInterface): void $apply */
    private function keep(\Closure $apply): void
    {
        $this->shared[] = $apply;
        foreach ($this->sharers as $engine) {
            $apply($engine);
        }
    }
}
