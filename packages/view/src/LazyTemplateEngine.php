<?php

declare(strict_types=1);

namespace Preflow\View;

/**
 * Stands in for an engine a TemplateEngineFactory builds: functions, globals and namespaces
 * registered before the first render are kept and handed to the engine when it is built (on
 * the first render() or exists()), in the order they came; later ones go straight to it.
 */
final class LazyTemplateEngine implements TemplateEngineInterface, RendersRegions
{
    private ?TemplateEngineInterface $engine = null;

    /** @var list<\Closure(TemplateEngineInterface): void> */
    private array $pending = [];

    /** @param \Closure(): TemplateEngineInterface $create */
    public function __construct(
        private readonly \Closure $create,
        private readonly string $extension,
    ) {}

    /** The engine itself (built now if it wasn't yet). */
    public function engine(): TemplateEngineInterface
    {
        if ($this->engine === null) {
            $engine = ($this->create)();
            foreach ($this->pending as $apply) {
                $apply($engine);
            }
            $this->pending = [];
            $this->engine = $engine;
        }

        return $this->engine;
    }

    public function render(string $template, array $context = []): string
    {
        return $this->engine()->render($template, $context);
    }

    /**
     * The wrapped engine's region (since 0.86.0).
     *
     * @throws \LogicException the wrapped engine renders no regions (named in the message)
     */
    public function renderRegion(string $template, string $region, array $context): string
    {
        return $this->regions()->renderRegion($template, $region, $context);
    }

    /**
     * The wrapped engine's regions of $template (since 0.86.0).
     *
     * @throws \LogicException the wrapped engine renders no regions (named in the message)
     */
    public function regionsOf(string $template): array
    {
        return $this->regions()->regionsOf($template);
    }

    /** The wrapped engine, as one that renders regions. */
    private function regions(): RendersRegions
    {
        $engine = $this->engine();

        return $engine instanceof RendersRegions ? $engine : throw Regions::notRendered($engine);
    }

    public function exists(string $template): bool
    {
        return $this->engine()->exists($template);
    }

    public function addFunction(TemplateFunctionDefinition $function): void
    {
        $this->apply(static fn (TemplateEngineInterface $engine) => $engine->addFunction($function));
    }

    public function addGlobal(string $name, mixed $value): void
    {
        $this->apply(static fn (TemplateEngineInterface $engine) => $engine->addGlobal($name, $value));
    }

    public function getTemplateExtension(): string
    {
        return $this->engine?->getTemplateExtension() ?? $this->extension;
    }

    public function addNamespace(string $namespace, string $path): void
    {
        $this->apply(static fn (TemplateEngineInterface $engine) => $engine->addNamespace($namespace, $path));
    }

    /** @param \Closure(TemplateEngineInterface): void $call */
    private function apply(\Closure $call): void
    {
        if ($this->engine !== null) {
            $call($this->engine);

            return;
        }
        $this->pending[] = $call;
    }
}
