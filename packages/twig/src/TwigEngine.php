<?php

declare(strict_types=1);

namespace Preflow\Twig;

use Preflow\Core\PageCache\LiveParts;
use Preflow\Core\PageCache\LiveRecipeRejected;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;
use Preflow\View\AssetCollector;
use Preflow\View\Geo\GeoCollector;
use Preflow\View\Seo\SeoContext;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;

final class TwigEngine implements TemplateEngineInterface
{
    private readonly Environment $twig;

    /**
     * @param string[] $templateDirs Directories to search for templates
     */
    public function __construct(
        array $templateDirs,
        AssetCollector $assetCollector,
        bool $debug = false,
        ?string $cachePath = null,
        ?GeoCollector $geoCollector = null,
        ?SeoContext $seoContext = null,
        ?LiveParts $liveParts = null,
        ?\Preflow\Core\PageCache\PageCache $pageCache = null,
    ) {
        $loader = new FilesystemLoader($templateDirs);

        $this->twig = new Environment($loader, [
            'debug' => $debug,
            'cache' => $cachePath ?: false,
            'auto_reload' => true,
            'strict_variables' => $debug,
            'autoescape' => 'html',
        ]);

        $this->twig->addExtension(new PreflowExtension($assetCollector, $geoCollector, $seoContext));
        $this->twig->addExtension(new LiveExtension($liveParts));
        $this->twig->addExtension(new FreshnessExtension($pageCache));
        $liveParts?->register(LiveExtension::KIND, fn (array $recipe): string => $this->renderLiveBlock($recipe));
    }

    /**
     * A {% live %} block of a served page. Only a block the live tag declared, in a template
     * the loader finds by its logical name or in a live-capable component's own template (by
     * class, never by path); anything else is rejected (LiveRecipeRejected) and stays a comment.
     *
     * @param array<string, mixed> $recipe
     */
    private function renderLiveBlock(array $recipe): string
    {
        $block = $recipe['b'] ?? null;
        $with = $recipe['w'] ?? [];
        if (!is_string($block) || preg_match(LiveExtension::BLOCK_PATTERN, $block) !== 1 || !is_array($with)) {
            throw new LiveRecipeRejected('A live block recipe without a live block or values.');
        }
        $name = $recipe['t'] ?? null;
        $class = $recipe['c'] ?? null;
        if (is_string($name)) {
            if (!LiveExtension::logical($name) || !$this->twig->getLoader()->exists($name)) {
                throw new LiveRecipeRejected("A live block recipe names no template of the loader: {$name}.");
            }
            $template = $this->twig->load($name);
        } elseif (is_string($class) && is_subclass_of($class, \Preflow\Components\Component::class)) {
            $ref = new \ReflectionClass($class);
            $path = dirname((string) $ref->getFileName()) . '/' . $ref->getShortName() . '.twig';
            if (!is_file($path)) {
                throw new LiveRecipeRejected("The component {$class} has no Twig template.");
            }
            // As render() loads a component template by its file.
            $template = $this->twig->createTemplate((string) file_get_contents($path), $path);
        } else {
            throw new LiveRecipeRejected('A live block recipe names neither a template nor a component.');
        }
        if (!$template->hasBlock($block)) {
            throw new LiveRecipeRejected("{$block} is not a live block of its template.");
        }

        return $template->renderBlock($block, $with);
    }

    public function render(string $template, array $context = []): string
    {
        // Absolute path (e.g., component templates) — load directly from filesystem
        if (str_starts_with($template, '/') && file_exists($template)) {
            $source = file_get_contents($template);
            $tpl = $this->twig->createTemplate($source, $template);
            return $tpl->render($context);
        }

        return $this->twig->render($template, $context);
    }

    public function exists(string $template): bool
    {
        if (str_starts_with($template, '/')) {
            return file_exists($template);
        }

        return $this->twig->getLoader()->exists($template);
    }

    public function addFunction(TemplateFunctionDefinition $function): void
    {
        $options = $function->isSafe ? ['is_safe' => ['html']] : [];
        $this->twig->addFunction(new TwigFunction(
            $function->name,
            $function->callable,
            $options,
        ));
    }

    public function addGlobal(string $name, mixed $value): void
    {
        $this->twig->addGlobal($name, $value);
    }

    public function getTemplateExtension(): string
    {
        return 'twig';
    }

    public function addNamespace(string $namespace, string $path): void
    {
        /** @var \Twig\Loader\FilesystemLoader $loader */
        $loader = $this->twig->getLoader();
        $loader->addPath($path, $namespace);
    }
}
