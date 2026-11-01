<?php

declare(strict_types=1);

namespace Preflow\Blade;

use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use Preflow\View\AssetCollector;
use Preflow\View\Geo\GeoCollector;
use Preflow\View\Seo\SeoContext;
use Preflow\View\TemplateFunctionDefinition;
use Preflow\View\TemplateEngineInterface;

final class BladeEngine implements TemplateEngineInterface
{
    private readonly Factory $viewFactory;
    private readonly BladeCompiler $compiler;
    private array $globals = [];

    /**
     * @param string[] $templateDirs
     */
    public function __construct(
        array $templateDirs,
        AssetCollector $assetCollector,
        bool $debug = false,
        ?string $cachePath = null,
        ?GeoCollector $geoCollector = null,
        ?SeoContext $seoContext = null,
    ) {
        $filesystem = new Filesystem();
        $cachePath ??= sys_get_temp_dir() . '/preflow_blade_cache';

        if (!is_dir($cachePath)) {
            mkdir($cachePath, 0755, true);
        }

        $this->compiler = new BladeCompiler($filesystem, $cachePath);

        // Register @css / @endcss directive pair
        $this->registerAssetDirectives($assetCollector);

        $resolver = new EngineResolver();
        $resolver->register('blade', fn () => new CompilerEngine($this->compiler, $filesystem));

        $finder = new FileViewFinder($filesystem, $templateDirs);

        $this->viewFactory = new Factory($resolver, $finder, new Dispatcher());

        // Share the asset collector so compiled templates can access it
        $this->viewFactory->share('__assetCollector', $assetCollector);
        $this->viewFactory->share('__geoCollector', $geoCollector);
        $this->viewFactory->share('__seoContext', $seoContext);
    }

    public function render(string $template, array $context = []): string
    {
        $context = array_merge($this->globals, $context);

        // Absolute path -- rendered as that very file (a name lookup would let two
        // same-named files in different folders collide); its directory becomes a
        // location so it can @include siblings by name (deduplicated to avoid finder bloat).
        if (str_starts_with($template, '/') && file_exists($template)) {
            $dir = dirname($template);
            $finder = $this->viewFactory->getFinder();
            if (!in_array($dir, $finder->getPaths(), true)) {
                $finder->addLocation($dir);
            }
            return $this->viewFactory->file($template, $context)->render();
        }

        return $this->viewFactory->make($template, $context)->render();
    }

    public function exists(string $template): bool
    {
        try {
            $this->viewFactory->getFinder()->find($template);
            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    public function addFunction(TemplateFunctionDefinition $function): void
    {
        $name = $function->name;
        $callable = $function->callable;
        $isSafe = $function->isSafe;

        // Store callable as a shared view variable for runtime access
        $this->viewFactory->share("__fn_{$name}", $callable);
        $this->globals["__fn_{$name}"] = $callable;

        // Register Blade directive that invokes the stored callable
        $this->compiler->directive($name, function (string $expression) use ($name, $isSafe) {
            if ($isSafe) {
                return "<?php echo \$__fn_{$name}({$expression}); ?>";
            }
            return "<?php echo e(\$__fn_{$name}({$expression})); ?>";
        });
    }

    public function addGlobal(string $name, mixed $value): void
    {
        $this->globals[$name] = $value;
        $this->viewFactory->share($name, $value);
    }

    public function getTemplateExtension(): string
    {
        return 'blade.php';
    }

    public function addNamespace(string $namespace, string $path): void
    {
        // Blade does not support Twig-style template namespaces; intentionally a no-op.
    }

    private function registerAssetDirectives(AssetCollector $assetCollector): void
    {
        $this->compiler->directive('css', function () {
            return '<?php ob_start(); ?>';
        });

        $this->compiler->directive('endcss', function () {
            return '<?php $__assetCollector->addCss(ob_get_clean()); ?>';
        });

        // @geo / @endgeo: Markdown for the geo collector; printed values are decoded to plain text
        $this->compiler->directive('geo', function () {
            return '<?php ob_start(); ?>';
        });

        $this->compiler->directive('endgeo', function () {
            return '<?php $__geoMarkdown = ob_get_clean(); '
                . 'if ($__geoCollector?->capturing()) { '
                . "\$__geoCollector->add(html_entity_decode(\$__geoMarkdown, ENT_QUOTES | ENT_HTML5, 'UTF-8')); "
                . '} unset($__geoMarkdown); ?>';
        });

        // @seo([...]): the page's meta for the SEO context; prints nothing, a no-op without one (SEO off)
        $this->compiler->directive('seo', function (string $expression) {
            return "<?php if (\$__seoContext ?? null) { \$__seoContext->mergeTemplate({$expression}); } ?>";
        });
    }
}
