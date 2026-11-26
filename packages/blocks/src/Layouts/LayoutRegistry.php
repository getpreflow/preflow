<?php

declare(strict_types=1);

namespace Preflow\Blocks\Layouts;

use Preflow\Blocks\BlockRegistry;

/**
 * All declared page layouts, by key, built from classes carrying #[Layout]: app/Layouts
 * (App\Layouts\…), the classes packages list in composer.json extra.preflow.layouts and
 * config/blocks.php 'layouts'. Cached like blocks (layouts.cache, rebuilt when a class changes).
 *
 * @since 0.92.0
 */
final class LayoutRegistry
{
    private const string KEY = '/^[a-z][a-z0-9_-]*$/';
    private const string AREA = '/^[a-z][a-z0-9_]*$/';

    /** @var array<string, AreaLayout> */
    private array $layouts = [];

    /** @param list<class-string> $classes */
    public function __construct(array $classes = [])
    {
        foreach ($classes as $class) {
            $layout = self::define($class);
            if ($layout !== null) {
                $this->add($layout);
            }
        }
    }

    /**
     * @param string             $layoutsDir app/Layouts (PSR-4 App\Layouts\…)
     * @param list<class-string> $classes    extra layout classes (packages' extra.preflow.layouts, config/blocks.php 'layouts')
     */
    public static function discover(string $layoutsDir, array $classes = [], ?string $cacheFile = null, bool $useCache = false): self
    {
        $all = array_values(array_unique([...BlockRegistry::scanClasses($layoutsDir, 'App\\Layouts\\'), ...$classes]));

        $signature = $useCache && $cacheFile !== null ? self::signature($all) : null;
        if ($signature !== null && is_file($cacheFile)) {
            $cached = @unserialize((string) file_get_contents($cacheFile), ['allowed_classes' => [AreaLayout::class, AreaDefinition::class]]);
            if (is_array($cached) && ($cached['signature'] ?? null) === $signature && is_array($cached['layouts'] ?? null)) {
                $registry = new self();
                foreach ($cached['layouts'] as $layout) {
                    if ($layout instanceof AreaLayout) {
                        $registry->add($layout);
                    }
                }

                return $registry;
            }
        }

        $registry = new self($all);

        if ($signature !== null) {
            @mkdir(dirname($cacheFile), 0755, true);
            @file_put_contents($cacheFile, serialize(['signature' => $signature, 'layouts' => array_values($registry->layouts)]), LOCK_EX);
        }

        return $registry;
    }

    /**
     * The layouts as plain data (the boot manifest stores it), for fromArray().
     *
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        $out = [];
        foreach ($this->layouts as $layout) {
            $row = get_object_vars($layout);
            $row['areas'] = array_values(array_map(static fn (AreaDefinition $area): array => get_object_vars($area), $layout->areas));
            $out[] = $row;
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $layouts toArray()'s */
    public static function fromArray(array $layouts): self
    {
        $registry = new self();
        foreach ($layouts as $row) {
            $areas = [];
            foreach ((array) $row['areas'] as $area) {
                $areas[$area['name']] = new AreaDefinition(...$area);
            }
            $row['areas'] = $areas;
            $registry->add(new AreaLayout(...$row));
        }

        return $registry;
    }

    public function get(string $key): ?AreaLayout
    {
        return $this->layouts[$key] ?? null;
    }

    /** @return array<string, AreaLayout> */
    public function all(): array
    {
        return $this->layouts;
    }

    /** @param list<class-string> $classes */
    private static function signature(array $classes): string
    {
        $parts = ['layout:1'];
        foreach ($classes as $class) {
            $file = class_exists($class) ? (new \ReflectionClass($class))->getFileName() : false;
            $parts[] = $class . '@' . ($file !== false && is_file($file) ? filemtime($file) : 0);
        }

        return hash('xxh3', implode('|', $parts));
    }

    private function add(AreaLayout $layout): void
    {
        if (isset($this->layouts[$layout->key])) {
            throw new LayoutConfigException(sprintf(
                'Layout key "%s" is declared by both %s and %s; give one of them another key.',
                $layout->key,
                $this->layouts[$layout->key]->class ?? '?',
                $layout->class ?? '?',
            ));
        }
        $this->layouts[$layout->key] = $layout;
    }

    /** @param class-string $class */
    private static function define(string $class): ?AreaLayout
    {
        if (!class_exists($class)) {
            return null;
        }
        $reflection = new \ReflectionClass($class);
        $attribute = $reflection->getAttributes(Layout::class, \ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;
        if ($attribute === null) {
            return null;
        }
        /** @var Layout $layout */
        $layout = $attribute->newInstance();

        if (preg_match(self::KEY, $layout->key) !== 1) {
            throw new LayoutConfigException(sprintf(
                'Layout key "%s" on %s is not allowed; start with a lowercase letter and use only lowercase letters, digits, "_" and "-".',
                $layout->key,
                $class,
            ));
        }

        $areas = [];
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $areaAttribute = $property->getAttributes(Area::class, \ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;
            if ($areaAttribute === null) {
                continue;
            }
            /** @var Area $area */
            $area = $areaAttribute->newInstance();
            $name = $property->getName();
            if (str_starts_with($name, '_')) {
                throw new LayoutConfigException(sprintf(
                    'Area "%s" on %s uses a reserved name (names starting with "_" are system keys); rename the property.',
                    $name,
                    $class,
                ));
            }
            if (preg_match(self::AREA, $name) !== 1) {
                throw new LayoutConfigException(sprintf(
                    'Area "%s" on %s is not allowed; start with a lowercase letter and use only lowercase letters, digits and "_".',
                    $name,
                    $class,
                ));
            }
            if ($area->min !== null && $area->max !== null && $area->min > $area->max) {
                throw new LayoutConfigException(sprintf(
                    'Area "%s" on %s has min %d above max %d; make min at most max.',
                    $name,
                    $class,
                    $area->min,
                    $area->max,
                ));
            }
            $areas[$name] = new AreaDefinition($name, $area->label, $area->allowed, $area->except, $area->min, $area->max, $area->help);
        }
        if ($areas === []) {
            throw new LayoutConfigException(sprintf(
                'Layout "%s" (%s) has no areas; add a public array property with #[%s] for each area.',
                $layout->key,
                $class,
                Area::class,
            ));
        }

        return new AreaLayout($layout->key, $layout->label, $areas, $layout->template, $layout->description, $layout->icon, $class);
    }
}
