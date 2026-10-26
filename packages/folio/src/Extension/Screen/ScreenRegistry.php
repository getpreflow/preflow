<?php

declare(strict_types=1);

namespace Preflow\Folio\Extension\Screen;

use Preflow\Core\Routing\RouteMode;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Extension\Attributes\FolioScreen;
use Preflow\Folio\Extension\ExtensionException;
use Preflow\Folio\Extension\ExtensionRegistry;
use Preflow\Folio\Routing\PatternCompiler;
use Preflow\Routing\Attributes\HttpMethod;
use Preflow\Routing\RouteEntry;

/**
 * The enabled screens (#[FolioScreen] parts of enabled extensions), validated at boot:
 * a bad path, a missing permission, or a path whose first segment is a type key, a Folio
 * path or another screen's path is an ExtensionException. Their routes go under the
 * Folio prefix; FolioPermissionMiddleware asks permissionFor() for their handlers.
 */
final class ScreenRegistry
{
    /** First path segments Folio uses under its prefix (FolioRoutes::admin) or keeps for itself. */
    public const array RESERVED = ['login', 'logout', '_assets', '_uploads', '_blocks', 'media', 'extensions', 'blocks', 'preview', 'upload', 'assets'];

    private const string AUTH_MW = 'Preflow\\Folio\\Http\\AdminAuthMiddleware';
    private const string LOCALE_MW = 'Preflow\\Folio\\Http\\AdminLocaleMiddleware';
    private const string PERMISSION_MW = 'Preflow\\Folio\\Http\\FolioPermissionMiddleware';
    private const string WORKING_MW = 'Preflow\\Folio\\Http\\WorkingCopyMiddleware';

    /**
     * @param array<class-string, array{extension: string, meta: FolioScreen, permission: string, assets: list<string>, actions: list<array{method: string, path: string, action: string}>}> $screens by class
     */
    private function __construct(
        private readonly array $screens,
    ) {}

    public static function fromExtensions(ExtensionRegistry $extensions, TypeCatalog $catalog): self
    {
        $screens = [];
        $paths = [];
        foreach ($extensions->enabled() as $definition) {
            foreach ($definition->parts as $part) {
                if ($part['kind'] !== 'screen' || !isset($part['class']) || !$extensions->partEnabled($definition->name, $part['id'])) {
                    continue;
                }
                $class = $part['class'];
                $screen = self::screen($definition->name, $class);
                $path = $screen['meta']->path;
                if (isset($paths[$path])) {
                    throw new ExtensionException("Screen path \"{$path}\" is declared by both {$paths[$path]} and {$class}.");
                }
                $first = explode('/', $path)[0];
                if (in_array($first, self::RESERVED, true)) {
                    throw new ExtensionException("Screen {$class}: path \"{$path}\" starts with \"{$first}\", which is reserved by Folio (" . implode(', ', self::RESERVED) . ').');
                }
                if ($catalog->has($first)) {
                    throw new ExtensionException("Screen {$class}: path \"{$path}\" starts with \"{$first}\", which is a content type key.");
                }
                $paths[$path] = $class;
                $screens[$class] = $screen;
            }
        }
        foreach ($screens as $class => $screen) {
            $first = explode('/', $screen['meta']->path)[0];
            if ($first !== $screen['meta']->path && isset($paths[$first])) {
                throw new ExtensionException("Screen {$class}: path \"{$screen['meta']->path}\" starts with \"{$first}\", which is the path of screen {$paths[$first]}.");
            }
        }

        return new self($screens);
    }

    /** @return list<array{class: class-string, extension: string, path: string, label: string, icon: string, section: string, order: int, permission: string, assets: list<string>}> */
    public function all(): array
    {
        $all = [];
        foreach (array_keys($this->screens) as $class) {
            $all[] = $this->get($class);
        }

        return $all;
    }

    /** @return array{class: class-string, extension: string, path: string, label: string, icon: string, section: string, order: int, permission: string, assets: list<string>}|null */
    public function get(string $class): ?array
    {
        $screen = $this->screens[$class] ?? null;
        if ($screen === null) {
            return null;
        }
        $meta = $screen['meta'];

        return [
            'class' => $class,
            'extension' => $screen['extension'],
            'path' => $meta->path,
            'label' => $meta->label,
            'icon' => $meta->icon,
            'section' => $meta->section,
            'order' => $meta->order,
            'permission' => $screen['permission'],
            'assets' => $meta->assets,
        ];
    }

    /** The permission an enabled screen class requires; null for any other class. */
    public function permissionFor(string $handlerClass): ?string
    {
        return $this->screens[$handlerClass]['permission'] ?? null;
    }

    /**
     * Each screen method as {prefix}/{path}{method path}: signed-in staff with the
     * screen's permission (with auth), then ScreenDispatcher answers.
     *
     * @return list<RouteEntry>
     */
    public function routes(string $prefix, bool $authEnabled): array
    {
        $base = rtrim('/' . trim($prefix, '/'), '/');
        $middleware = $authEnabled ? [self::AUTH_MW, self::LOCALE_MW, self::PERMISSION_MW] : [self::LOCALE_MW];
        $middleware[] = self::WORKING_MW; // the active sidebar item, working copies as on every admin route
        $middleware[] = ScreenDispatcher::class;

        $entries = [];
        $seen = [];
        foreach ($this->screens as $class => $screen) {
            foreach ($screen['actions'] as $action) {
                $pattern = $base . '/' . $screen['meta']->path . ($action['path'] === '/' ? '' : '/' . ltrim($action['path'], '/'));
                $key = $action['method'] . ' ' . $pattern;
                if (isset($seen[$key])) {
                    throw new ExtensionException("Route {$key} is declared by both {$seen[$key]} and {$class}@{$action['action']}.");
                }
                $seen[$key] = $class . '@' . $action['action'];
                $c = PatternCompiler::compile($pattern);
                $entries[] = new RouteEntry(
                    pattern: $pattern,
                    handler: $class . '@' . $action['action'],
                    method: $action['method'],
                    mode: RouteMode::Action,
                    middleware: $middleware,
                    paramNames: $c['paramNames'],
                    regex: $c['regex'],
                    isCatchAll: $c['isCatchAll'],
                );
            }
        }

        return $entries;
    }

    /**
     * The attribute and the #[Get]/#[Post] methods of one screen class (composed here
     * rather than with AttributeRouteScanner, which needs a class-level #[Route]).
     *
     * @return array{extension: string, meta: FolioScreen, permission: string, actions: list<array{method: string, path: string, action: string}>}
     */
    private static function screen(string $extension, string $class): array
    {
        if (!class_exists($class)) {
            throw new ExtensionException("Screen class {$class} does not exist.");
        }
        $reflection = new \ReflectionClass($class);
        $attribute = $reflection->getAttributes(FolioScreen::class)[0] ?? null;
        if ($attribute === null) {
            throw new ExtensionException("{$class} has no #[FolioScreen] attribute.");
        }
        try {
            /** @var FolioScreen $meta */
            $meta = $attribute->newInstance();
        } catch (\Error $e) {
            throw new ExtensionException("#[FolioScreen] on {$class}: {$e->getMessage()}", 0, $e);
        }
        if (preg_match('/^[a-z][a-z0-9-]*(\/[a-z0-9-]+)*$/', $meta->path) !== 1) {
            throw new ExtensionException("Screen {$class}: path \"{$meta->path}\" must match /^[a-z][a-z0-9-]*(\\/[a-z0-9-]+)*$/.");
        }
        if ($meta->permission === null || $meta->permission === '') {
            throw new ExtensionException("Screen {$class} needs a permission (#[FolioScreen(..., permission: '...')]).");
        }
        if (preg_match('/^[a-z][a-z0-9_-]*(\.[a-z0-9_-]+)+$/', $meta->permission) !== 1) {
            throw new ExtensionException("Screen {$class}: permission \"{$meta->permission}\" must match /^[a-z][a-z0-9_-]*(\\.[a-z0-9_-]+)+$/ (e.g. \"members.approve\").");
        }

        foreach ($meta->assets as $asset) {
            if (!is_string($asset) || $asset === '') {
                throw new ExtensionException("Screen {$class}: assets must be non-empty strings.");
            }
        }

        $actions = [];
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(HttpMethod::class, \ReflectionAttribute::IS_INSTANCEOF) as $routeAttribute) {
                $route = $routeAttribute->newInstance();
                if (!str_starts_with($route->path, '/')) {
                    throw new ExtensionException("Screen {$class}::{$method->getName()}: route path \"{$route->path}\" must start with \"/\".");
                }
                $actions[] = ['method' => $route->method, 'path' => $route->path, 'action' => $method->getName()];
            }
        }
        if (!in_array(['GET', '/'], array_map(fn (array $a): array => [$a['method'], $a['path']], $actions), true)) {
            throw new ExtensionException("Screen {$class} needs a #[Get('/')] method (its sidebar item links there).");
        }

        return ['extension' => $extension, 'meta' => $meta, 'permission' => $meta->permission, 'actions' => $actions];
    }
}
