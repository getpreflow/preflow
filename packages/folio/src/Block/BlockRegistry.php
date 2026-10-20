<?php

declare(strict_types=1);

namespace Preflow\Folio\Block;

use Preflow\Components\Component;
use Preflow\Components\ComponentMap;
use Preflow\Data\TypeFieldDefinition;

/** All declared blocks, by key, built from classes carrying #[Block]. */
final class BlockRegistry
{
    /** @var array<string, BlockDefinition> */
    private array $blocks = [];

    /** @param list<class-string> $classes */
    public function __construct(array $classes = [])
    {
        foreach ($classes as $class) {
            $definition = self::define($class);
            if ($definition !== null) {
                $this->add($definition);
            }
        }
    }

    /**
     * @param ComponentMap|array<string, class-string> $components discovered components
     * @param string                                   $blocksDir  app/Blocks (data-only blocks, PSR-4 App\Blocks\…)
     * @param list<class-string>                       $classes    extra block classes (config/folio.php 'blocks')
     */
    public static function discover(
        ComponentMap|array $components,
        string $blocksDir,
        array $classes = [],
        ?string $cacheFile = null,
        bool $useCache = false,
    ): self {
        $all = array_values($components instanceof ComponentMap ? $components->all() : $components);
        $all = array_values(array_unique([...$all, ...self::scanBlocksDir($blocksDir), ...$classes]));

        // The cache is keyed by the candidate classes and their files' mtimes, so
        // adding, removing or editing a block rebuilds it (no manual clearing).
        $signature = $useCache && $cacheFile !== null ? self::signature($all) : null;
        if ($signature !== null && is_file($cacheFile)) {
            $cached = @unserialize((string) file_get_contents($cacheFile), ['allowed_classes' => [BlockDefinition::class, TypeFieldDefinition::class]]);
            if (is_array($cached) && ($cached['signature'] ?? null) === $signature && is_array($cached['blocks'] ?? null)) {
                $registry = new self();
                foreach ($cached['blocks'] as $definition) {
                    if ($definition instanceof BlockDefinition) {
                        $registry->add($definition);
                    }
                }
                return $registry;
            }
        }

        $registry = new self($all);

        if ($signature !== null) {
            @mkdir(dirname($cacheFile), 0755, true);
            @file_put_contents($cacheFile, serialize(['signature' => $signature, 'blocks' => array_values($registry->blocks)]), LOCK_EX);
        }

        return $registry;
    }

    public function get(string $key): ?BlockDefinition
    {
        return $this->blocks[$key] ?? null;
    }

    /** @return array<string, BlockDefinition> */
    public function all(): array
    {
        return $this->blocks;
    }

    /**
     * Allowed blocks by group, in declaration order.
     *
     * @param list<string> $allowed
     * @return array<string, list<BlockDefinition>>
     */
    public function grouped(array $allowed): array
    {
        $groups = [];
        foreach ($this->blocks as $key => $definition) {
            if (in_array($key, $allowed, true)) {
                $groups[$definition->group][] = $definition;
            }
        }

        return $groups;
    }

    /** @param list<class-string> $classes */
    private static function signature(array $classes): string
    {
        $parts = [];
        foreach ($classes as $class) {
            $file = class_exists($class) ? (new \ReflectionClass($class))->getFileName() : false;
            $parts[] = $class . '@' . ($file !== false && is_file($file) ? filemtime($file) : 0);
        }

        return hash('xxh3', implode('|', $parts));
    }

    private function add(BlockDefinition $definition): void
    {
        if (isset($this->blocks[$definition->key])) {
            throw new BlockConfigException(sprintf(
                'Block key "%s" is declared by both %s and %s.',
                $definition->key,
                $this->blocks[$definition->key]->class,
                $definition->class,
            ));
        }
        $this->blocks[$definition->key] = $definition;
    }

    /** @param class-string $class */
    private static function define(string $class): ?BlockDefinition
    {
        if (!class_exists($class)) {
            return null;
        }
        $reflection = new \ReflectionClass($class);
        $attribute = $reflection->getAttributes(Block::class)[0] ?? null;
        if ($attribute === null) {
            return null;
        }
        /** @var Block $block */
        $block = $attribute->newInstance();

        $fields = [];
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $fieldAttribute = $property->getAttributes(BlockField::class)[0] ?? null;
            if ($fieldAttribute === null) {
                continue;
            }
            /** @var BlockField $field */
            $field = $fieldAttribute->newInstance();
            if (str_starts_with($property->getName(), '_')) {
                throw new BlockConfigException(sprintf(
                    'Block field "%s" on %s uses a reserved name (names starting with "_" are system keys such as _uid, _block, _order).',
                    $property->getName(),
                    $class,
                ));
            }
            $fields[$property->getName()] = new TypeFieldDefinition(
                name: $property->getName(),
                type: $field->type,
                validate: $field->validate,
                label: $field->label,
                help: $field->help,
                config: $field->fieldConfig(),
                translatable: $field->translatable,
            );
        }

        return new BlockDefinition(
            key: $block->key,
            label: $block->label,
            group: $block->group,
            icon: $block->icon,
            description: $block->description,
            class: $class,
            isComponent: $reflection->isSubclassOf(Component::class),
            fields: $fields,
        );
    }

    /** @return list<class-string> data-only block classes under app/Blocks (App\Blocks\…) */
    private static function scanBlocksDir(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $classes = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen(rtrim($dir, '/')) + 1, -4);
            $classes[] = 'App\\Blocks\\' . str_replace('/', '\\', $relative);
        }
        sort($classes);

        return $classes;
    }
}
