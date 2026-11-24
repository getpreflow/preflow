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
use Preflow\View\Regions;
use Preflow\View\RendersRegions;
use Preflow\View\Seo\SeoContext;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;

final class TwigEngine implements TemplateEngineInterface, RendersRegions
{
    private readonly Environment $twig;

    /** @var array<string, class-string> the component templates render() was given: Twig's name for the file => the component owning it */
    private array $componentTemplates = [];

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

        $this->twig->getRuntime(\Twig\Runtime\EscaperRuntime::class)->addSafeClass(\Preflow\View\SafeHtml::class, ['html']);

        $this->twig->addExtension(new PreflowExtension($assetCollector, $geoCollector, $seoContext));
        $this->twig->addExtension(new LiveExtension($liveParts, fn (string $name): ?string => $this->componentTemplates[$name] ?? null));
        $this->twig->addExtension(new FreshnessExtension($pageCache));
        $this->twig->addExtension(new RegionExtension());
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
        } elseif (is_string($class) && ($path = self::componentTemplate($class)) !== null) {
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

    /** The file of $class's own Twig template (as Component::getTemplatePath()), null when $class is no component. */
    private static function componentTemplate(string $class): ?string
    {
        if (!is_subclass_of($class, \Preflow\Components\Component::class)) {
            return null;
        }
        $ref = new \ReflectionClass($class);

        return dirname((string) $ref->getFileName()) . '/' . $ref->getShortName() . '.twig';
    }

    /**
     * A file rendered with a `componentClass` whose own template it is (as ComponentRenderer
     * renders one) is that component's template: its live blocks are named by that class (since
     * 0.82.0), whatever the variables inside it say.
     *
     * An HttpException (a 403, a 404…) thrown while the template renders, by a component or a
     * function it calls, comes out as itself, not wrapped in Twig's RuntimeError: the page
     * answers with its status.
     */
    public function render(string $template, array $context = []): string
    {
        return $this->unwrapHttp(fn (): string => $this->load($template, $context)->render($context));
    }

    /**
     * The contents of the {% region %} $region of $template (since 0.86.0): its block, rendered
     * with $context and the globals, without the region element.
     */
    public function renderRegion(string $template, string $region, array $context): string
    {
        return $this->unwrapHttp(function () use ($template, $region, $context): string {
            $tpl = $this->load($template, $context);
            $block = RegionExtension::blockName($region);
            if (!Regions::validName($region) || !$tpl->hasBlock($block)) {
                throw Regions::unknown($template, $region, self::regionNames($tpl));
            }

            return $tpl->renderBlock($block, $context);
        });
    }

    /** The {% region %}s of $template, in the order they appear (since 0.86.0). */
    public function regionsOf(string $template): array
    {
        return self::regionNames($this->load($template, []));
    }

    /** @return list<string> */
    private static function regionNames(\Twig\TemplateWrapper $template): array
    {
        $names = [];
        foreach ($template->getBlockNames() as $block) {
            $name = RegionExtension::regionName((string) $block);
            if ($name !== null) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * $template loaded: an absolute path (a component's template) from its file, anything else
     * by the loader.
     *
     * @param array<string, mixed> $context
     */
    private function load(string $template, array $context): \Twig\TemplateWrapper
    {
        // Absolute path (e.g., component templates) — load directly from filesystem
        if (str_starts_with($template, '/') && file_exists($template)) {
            $source = (string) file_get_contents($template);
            $tpl = $this->twig->createTemplate($source, $template);
            $class = $context['componentClass'] ?? null;
            if (is_string($class) && !isset($this->componentTemplates[$tpl->getTemplateName()]) && self::componentTemplate($class) === $template) {
                $this->componentTemplates[$tpl->getTemplateName()] = $class; // the name holds the file and its source hash: one owner
            }

            return $tpl;
        }

        return $this->twig->load($template);
    }

    /**
     * $render's result; an HttpException thrown inside it comes out as itself, not wrapped in
     * Twig's RuntimeError.
     *
     * @param \Closure(): string $render
     */
    private function unwrapHttp(\Closure $render): string
    {
        try {
            return $render();
        } catch (\Twig\Error\Error $e) {
            for ($cause = $e->getPrevious(); $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof \Preflow\Core\Exceptions\HttpException) {
                    throw $cause;
                }
            }
            throw $e;
        }
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
