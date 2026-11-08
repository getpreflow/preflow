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
use Preflow\Core\PageCache\LiveParts;
use Preflow\Core\PageCache\LivePlaceholder;
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

    /** The kind of a Blade live block's placeholder (LiveParts). */
    public const string LIVE_KIND = 'blade';

    /** @live(['number' => $n]) … @endlive; the arguments may be left out. Not nested. */
    private const string LIVE_PATTERN = '/(?<![@\w])@live(?![\w])\s*(\((?:[^()]++|(?1))*\))?(.*?)(?<![@\w])@endlive(?![\w])/s';

    private readonly string $cachePath;

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
        private readonly ?LiveParts $liveParts = null,
    ) {
        $filesystem = new Filesystem();
        $cachePath ??= sys_get_temp_dir() . '/preflow_blade_cache';

        if (!is_dir($cachePath)) {
            mkdir($cachePath, 0755, true);
        }
        $this->cachePath = $cachePath;

        $this->compiler = new BladeCompiler($filesystem, $cachePath);

        // @live … @endlive, taken out of the source before Blade compiles it (raw @php blocks too)
        $this->compiler->prepareStringsForCompilationUsing(fn (string $value): string => $this->compileLive($value));

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
        $this->viewFactory->share('__live', $this);
        $liveParts?->register(self::LIVE_KIND, fn (array $recipe): string => $this->renderLiveBlock($recipe));
    }

    /**
     * A live block (@live): its placeholder while the page renders for the page cache, else its
     * body rendered with only $with and the globals.
     *
     * @internal compiled templates
     * @param string $file the template's source file
     * @param int $index which @live of it
     * @param string $hash the body's hash (its extracted view file)
     */
    public function live(string $file, int $index, string $hash, mixed $with = []): string
    {
        if (!is_array($with)) {
            throw new \InvalidArgumentException(sprintf('@live() takes an array of values, %s given.', get_debug_type($with)));
        }
        LivePlaceholder::assertPlain($with);
        if ($this->liveParts !== null && ($placeholder = $this->liveParts->placeholder(self::LIVE_KIND, ['f' => $file, 'i' => $index, 'h' => $hash, 'w' => $with])) !== null) {
            return $placeholder;
        }

        return $this->renderLiveBody($file, $index, $hash, $with);
    }

    /**
     * Replaces each @live … @endlive of $value with a call of live(); its body goes into a view
     * file of its own (storage/cache/blade/live/{hash}.blade.php), rendered with only its values.
     */
    private function compileLive(string $value): string
    {
        if (!str_contains($value, '@live')) {
            return $value;
        }
        $file = (string) $this->compiler->getPath();
        $index = 0;

        return (string) preg_replace_callback(self::LIVE_PATTERN, function (array $m) use ($file, &$index): string {
            $body = $m[2];
            if (preg_match('/(?<![@\w])@live(?![\w])/', $body) === 1) {
                throw new \InvalidArgumentException("@live blocks can't be nested ({$file}).");
            }
            if ($file === '') {
                throw new \InvalidArgumentException('@live needs a template file (a Blade string has none).');
            }
            $hash = $this->writeLiveBody($body);
            $args = isset($m[1]) && $m[1] !== '' ? trim(substr($m[1], 1, -1)) : '';

            return '<?php echo $__live->live(' . var_export($file, true) . ', ' . $index++ . ', ' . var_export($hash, true)
                . ($args !== '' ? ', ' . $args : '') . '); ?>';
        }, $value);
    }

    /** Writes a live block's body as a view file, once; returns its hash. */
    private function writeLiveBody(string $body): string
    {
        $hash = hash('xxh128', $body);
        $path = $this->cachePath . '/live/' . $hash . '.blade.php';
        if (!is_file($path)) {
            @mkdir(dirname($path), 0755, true);
            $tmp = $path . '.' . bin2hex(random_bytes(4));
            file_put_contents($tmp, $body);
            rename($tmp, $path);
        }

        return $hash;
    }

    /**
     * A live block's body rendered with $with: from its view file, written again from the
     * template's source when it is gone (a cleared cache).
     *
     * @param array<string, mixed> $with
     */
    private function renderLiveBody(string $file, int $index, string $hash, array $with): string
    {
        $path = $this->cachePath . '/live/' . $hash . '.blade.php';
        if (!is_file($path)) {
            $source = is_file($file) ? (string) file_get_contents($file) : '';
            if (preg_match_all(self::LIVE_PATTERN, $source, $blocks) <= $index) {
                throw new \RuntimeException("The live block {$index} of {$file} is gone.");
            }
            $path = $this->cachePath . '/live/' . $this->writeLiveBody($blocks[2][$index]) . '.blade.php';
        }

        return $this->viewFactory->file($path, array_merge($this->globals, $with))->render();
    }

    /** @param array<string, mixed> $recipe a served page's @live placeholder */
    private function renderLiveBlock(array $recipe): string
    {
        $file = $recipe['f'] ?? null;
        $index = $recipe['i'] ?? null;
        $hash = $recipe['h'] ?? null;
        $with = $recipe['w'] ?? [];
        if (!is_string($file) || !is_int($index) || !is_string($hash) || preg_match('/^[0-9a-f]{32}$/', $hash) !== 1 || !is_array($with)) {
            throw new \RuntimeException('A live block placeholder without file, index, hash or values.');
        }

        return $this->renderLiveBody($file, $index, $hash, $with);
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
