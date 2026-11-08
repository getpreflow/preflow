<?php

declare(strict_types=1);

namespace Preflow\Twig;

use Preflow\Core\PageCache\LiveParts;
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
        $liveParts?->register(LiveExtension::KIND, fn (array $recipe): string => $this->renderLiveBlock($recipe));
    }

    /**
     * A {% live %} block of a served page: its template (a component template by its file, as
     * render() loads it), block and with values from the placeholder.
     *
     * @param array<string, mixed> $recipe
     */
    private function renderLiveBlock(array $recipe): string
    {
        $name = $recipe['t'] ?? null;
        $block = $recipe['b'] ?? null;
        $with = $recipe['w'] ?? [];
        if (!is_string($name) || !is_string($block) || !is_array($with)) {
            throw new \RuntimeException('A live block placeholder without template, block or values.');
        }
        // render() of an absolute path: createTemplate() names it "{path} (string template {hash})".
        if (preg_match('/^(\/.+) \(string template [0-9a-f]+\)$/', $name, $m) === 1 && is_file($m[1])) {
            $template = $this->twig->createTemplate((string) file_get_contents($m[1]), $m[1]);
        } else {
            $template = $this->twig->load($name);
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
