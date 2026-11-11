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
use Preflow\Core\PageCache\LiveRecipeRejected;
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
        private readonly array $templateDirs,
        AssetCollector $assetCollector,
        bool $debug = false,
        ?string $cachePath = null,
        ?GeoCollector $geoCollector = null,
        ?SeoContext $seoContext = null,
        private readonly ?LiveParts $liveParts = null,
        ?\Preflow\Core\PageCache\PageCache $pageCache = null,
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
        // @freshness / @expires; without the page cache, one without a recorder checks the arguments
        $this->viewFactory->share('__pageCache', $pageCache ?? new \Preflow\Core\PageCache\PageCache());
        $liveParts?->register(self::LIVE_KIND, fn (array $recipe): string => $this->renderLiveBlock($recipe));
    }

    /**
     * A live block (@live): its placeholder while the page renders for the page cache, else its
     * body rendered with only $with and the globals. The recipe names the template by its view
     * name ($view, relative to the template folders) or, for a component's own template, by
     * the component class; one that is neither renders in place and keeps the page from being
     * stored.
     *
     * @internal compiled templates
     * @param string|null $view the template's view name, null outside the template folders
     * @param int $index which @live of it
     * @param string $hash the body's hash (its extracted view file)
     */
    public function live(?string $view, mixed $componentClass, int $index, string $hash, mixed $with = []): string
    {
        if (!is_array($with)) {
            throw new \InvalidArgumentException(sprintf('@live() takes an array of values, %s given.', get_debug_type($with)));
        }
        LivePlaceholder::assertPlain($with);
        if ($this->liveParts !== null) {
            $recipe = $view !== null
                ? ['n' => $view, 'i' => $index, 'h' => $hash, 'w' => $with]
                : (is_string($componentClass) && $componentClass !== '' ? ['c' => $componentClass, 'i' => $index, 'h' => $hash, 'w' => $with] : null);
            if ($recipe === null) {
                $this->liveParts->renderedInPlace('blade-template');
            } elseif (($placeholder = $this->liveParts->placeholder(self::LIVE_KIND, $recipe)) !== null) {
                return $placeholder;
            }
        }

        return $this->renderLiveBody($this->livePath($hash), $with);
    }

    /**
     * Replaces each @live … @endlive of $value with a call of live(); its body goes into a view
     * file of its own (storage/cache/blade/live/{hash}.blade.php), rendered with only its values.
     * That file is the block's declaration: only bodies written here are ever filled.
     */
    private function compileLive(string $value): string
    {
        if (!str_contains($value, '@live')) {
            return $value;
        }
        $file = (string) $this->compiler->getPath();
        $view = $file === '' ? null : $this->viewName($file);
        $index = 0;

        return (string) preg_replace_callback(self::LIVE_PATTERN, function (array $m) use ($file, $view, &$index): string {
            $body = $m[2];
            if (preg_match('/(?<![@\w])@live(?![\w])/', $body) === 1) {
                throw new \InvalidArgumentException("@live blocks can't be nested ({$file}).");
            }
            if ($file === '') {
                throw new \InvalidArgumentException('@live needs a template file (a Blade string has none).');
            }
            $hash = $this->writeLiveBody($body);
            $args = isset($m[1]) && $m[1] !== '' ? trim(substr($m[1], 1, -1)) : '';

            return '<?php echo $__live->live(' . var_export($view, true) . ', $componentClass ?? null, ' . $index++ . ', ' . var_export($hash, true)
                . ($args !== '' ? ', ' . $args : '') . '); ?>';
        }, $value);
    }

    /** The view name of a template file in one of the template folders ("partials/card"), null elsewhere. */
    private function viewName(string $file): ?string
    {
        $real = realpath($file);
        if ($real === false || !str_ends_with($real, '.blade.php')) {
            return null;
        }
        foreach ($this->templateDirs as $dir) {
            $root = realpath($dir);
            if ($root !== false && str_starts_with($real, rtrim($root, '/') . '/')) {
                return substr($real, strlen(rtrim($root, '/')) + 1, -strlen('.blade.php'));
            }
        }

        return null;
    }

    private function livePath(string $hash): string
    {
        return $this->cachePath . '/live/' . $hash . '.blade.php';
    }

    /** Writes a live block's body as a view file, once; returns its hash. */
    private function writeLiveBody(string $body): string
    {
        $hash = hash('xxh128', $body);
        $path = $this->livePath($hash);
        if (!is_file($path)) {
            @mkdir(dirname($path), 0755, true);
            $tmp = $path . '.' . bin2hex(random_bytes(4));
            file_put_contents($tmp, $body);
            rename($tmp, $path);
        }

        return $hash;
    }

    /** @param array<string, mixed> $with */
    private function renderLiveBody(string $path, array $with): string
    {
        return $this->viewFactory->file($path, array_merge($this->globals, $with))->render();
    }

    /**
     * A served page's @live placeholder: the body file the engine wrote for that hash, or, after
     * a cleared cache, the block taken again from its template (found by view name in the
     * template folders, or as a component's own template by class), only when its body still
     * has that hash. Anything else is rejected (LiveRecipeRejected) and stays a comment.
     *
     * @param array<string, mixed> $recipe
     */
    private function renderLiveBlock(array $recipe): string
    {
        $index = $recipe['i'] ?? null;
        $hash = $recipe['h'] ?? null;
        $with = $recipe['w'] ?? [];
        if (!is_int($index) || $index < 0 || !is_string($hash) || preg_match('/^[0-9a-f]{32}$/', $hash) !== 1 || !is_array($with)) {
            throw new LiveRecipeRejected('A live block placeholder without index, hash or values.');
        }
        $path = $this->livePath($hash);
        if (!is_file($path)) {
            $source = $this->liveSource($recipe);
            if (preg_match_all(self::LIVE_PATTERN, (string) file_get_contents($source), $blocks) <= $index
                || hash('xxh128', $blocks[2][$index]) !== $hash) {
                throw new LiveRecipeRejected("The live block {$index} of {$source} is not the one stored.");
            }
            $path = $this->livePath($this->writeLiveBody($blocks[2][$index]));
        }

        return $this->renderLiveBody($path, $with);
    }

    /** @param array<string, mixed> $recipe */
    private function liveSource(array $recipe): string
    {
        $view = $recipe['n'] ?? null;
        $class = $recipe['c'] ?? null;
        if (is_string($view)) {
            if ($view === '' || str_starts_with($view, '/') || str_contains($view, '..') || str_contains($view, "\0") || str_contains($view, '::')) {
                throw new LiveRecipeRejected("A live block recipe names no view: {$view}.");
            }
            foreach ($this->templateDirs as $dir) {
                $file = rtrim($dir, '/') . '/' . $view . '.blade.php';
                if (is_file($file)) {
                    return $file;
                }
            }
            throw new LiveRecipeRejected("A live block recipe names no view: {$view}.");
        }
        if (is_string($class) && is_subclass_of($class, \Preflow\Components\Component::class)) {
            $ref = new \ReflectionClass($class);
            $file = dirname((string) $ref->getFileName()) . '/' . $ref->getShortName() . '.blade.php';
            if (is_file($file)) {
                return $file;
            }
        }
        throw new LiveRecipeRejected('A live block recipe names neither a view nor a component.');
    }

    /**
     * $template is a view name ("partials.card", "partials/card"), an absolute path, or a file
     * path relative to a template folder ("about.blade.php", "blog/[slug].blade.php": what
     * file-based routing passes for a page in app/pages).
     */
    public function render(string $template, array $context = []): string
    {
        try {
            return $this->renderTemplate($template, $context);
        } catch (\Preflow\Core\Exceptions\HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // An HttpException (a 403, a 404…) a component or function threw comes out as itself,
            // not wrapped in the view's exception: the page answers with its status.
            for ($cause = $e->getPrevious(); $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof \Preflow\Core\Exceptions\HttpException) {
                    throw $cause;
                }
            }
            throw $e;
        }
    }

    /** @param array<string, mixed> $context */
    private function renderTemplate(string $template, array $context): string
    {
        $context = array_merge($this->globals, $context);
        $file = $this->templateFile($template);

        // Absolute path -- rendered as that very file (a name lookup would let two
        // same-named files in different folders collide); its directory becomes a
        // location so it can @include siblings by name (deduplicated to avoid finder bloat).
        if ($file !== null || (str_starts_with($template, '/') && file_exists($template))) {
            $template = $file ?? $template;
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
        if ($this->templateFile($template) !== null) {
            return true;
        }
        try {
            $this->viewFactory->getFinder()->find($template);
            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /** The file a template path relative to a template folder names ("about.blade.php"), or null. */
    private function templateFile(string $template): ?string
    {
        if (
            !str_ends_with($template, '.blade.php')
            || str_starts_with($template, '/')
            || str_contains($template, "\0")
            || in_array('..', explode('/', $template), true)
        ) {
            return null;
        }
        foreach ($this->templateDirs as $dir) {
            $file = rtrim($dir, '/') . '/' . $template;
            if (is_file($file)) {
                return $file;
            }
        }

        return null;
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

    /**
     * A Blade view namespace: templates reference it as "ns::name" (Blade's syntax, not
     * Twig's "@namespace/…"). Paths are searched in registration order (first wins).
     */
    public function addNamespace(string $namespace, string $path): void
    {
        $this->viewFactory->addNamespace($namespace, $path);
    }

    /** A directive's arguments as a call's (Blade hands them over without their parentheses). */
    private static function arguments(string $expression): string
    {
        return '(' . $expression . ')';
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

        // @freshness('next-visit') … @endfreshness, @freshness('timed', every: '12h') … @endfreshness:
        // what the body reads is a dependency of the cached page with that freshness (PageCache::enter())
        $this->compiler->directive('freshness', function (string $expression) {
            return '<?php $__pageCache->enter' . self::arguments($expression) . '; try { ?>';
        });
        $this->compiler->directive('endfreshness', function () {
            return '<?php } finally { $__pageCache->leave(); } ?>';
        });

        // @expires(at: $event->startsAt) / @expires(in: '1h'): the cached page is stale from then on
        $this->compiler->directive('expires', function (string $expression) {
            return '<?php $__pageCache->expires' . self::arguments($expression) . '; ?>';
        });

        // @seo([...]): the page's meta for the SEO context; prints nothing, a no-op without one (SEO off)
        $this->compiler->directive('seo', function (string $expression) {
            return "<?php if (\$__seoContext ?? null) { \$__seoContext->mergeTemplate({$expression}); } ?>";
        });
    }
}
