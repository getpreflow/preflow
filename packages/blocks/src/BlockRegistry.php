<?php

declare(strict_types=1);

namespace Preflow\Blocks;

use Preflow\Components\Component;
use Preflow\Components\ComponentMap;
use Preflow\Data\FieldVisibility;
use Preflow\Data\SelectOptionsFrom;
use Preflow\Data\ShowWhenException;
use Preflow\Data\TypeFamily;
use Preflow\Data\TypeFieldDefinition;

/** All declared blocks, by key, built from classes carrying #[Block]. */
final class BlockRegistry
{
    /** @var array<string, BlockDefinition> */
    private array $blocks = [];

    /** @var array<string, true>|null the keys of blocks with a show_when field; null until first asked */
    private ?array $ruled = null;

    /**
     * @param list<class-string> $classes
     * @param list<\Closure(array<string, TypeFieldDefinition>): void> $checks packages' checks of each block's fields (BlockFieldChecks, since 0.91)
     */
    public function __construct(array $classes = [], array $checks = [])
    {
        foreach ($classes as $class) {
            $definition = self::define($class, $checks);
            if ($definition !== null) {
                $this->add($definition);
            }
        }
    }

    /**
     * @param ComponentMap|array<string, class-string> $components discovered components
     * @param string                                   $blocksDir  app/Blocks (data-only blocks, PSR-4 App\Blocks\…)
     * @param list<class-string>                       $classes    extra block classes (packages' extra.preflow.blocks, config/blocks.php 'classes')
     * @param list<\Closure(array<string, TypeFieldDefinition>): void> $checks packages' checks of each block's fields (BlockFieldChecks, since 0.91): run here, not for a cached registry
     */
    public static function discover(
        ComponentMap|array $components,
        string $blocksDir,
        array $classes = [],
        ?string $cacheFile = null,
        bool $useCache = false,
        array $checks = [],
    ): self {
        $all = array_values($components instanceof ComponentMap ? $components->all() : $components);
        $all = array_values(array_unique([...$all, ...self::scanBlocksDir($blocksDir), ...$classes]));

        // The cache is keyed by the candidate classes and their files' mtimes, so
        // adding, removing or editing a block rebuilds it (no manual clearing).
        $signature = $useCache && $cacheFile !== null ? self::signature($all) : null;
        if ($signature !== null && is_file($cacheFile)) {
            $cached = @unserialize((string) file_get_contents($cacheFile), ['allowed_classes' => [BlockDefinition::class, TypeFieldDefinition::class, TypeFamily::class]]);
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

        $registry = new self($all, $checks);

        if ($signature !== null) {
            @mkdir(dirname($cacheFile), 0755, true);
            @file_put_contents($cacheFile, serialize(['signature' => $signature, 'blocks' => array_values($registry->blocks)]), LOCK_EX);
        }

        return $registry;
    }

    /**
     * The blocks as plain data (the boot manifest stores it), for fromArray(). A family is its
     * value ("asset"), null when the block has none.
     *
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        $out = [];
        foreach ($this->blocks as $definition) {
            $block = get_object_vars($definition);
            $block['fields'] = array_map(static fn (TypeFieldDefinition $field): array => get_object_vars($field), $definition->fields);
            $block['family'] = $definition->family?->value;
            $out[] = $block;
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $blocks toArray()'s */
    public static function fromArray(array $blocks): self
    {
        $registry = new self();
        foreach ($blocks as $block) {
            $block['fields'] = array_map(static fn (array $field): TypeFieldDefinition => new TypeFieldDefinition(...$field), (array) $block['fields']);
            // A manifest written before 0.75 has no family.
            $block['family'] = is_string($block['family'] ?? null) ? TypeFamily::tryFrom($block['family']) : null;
            $registry->add(new BlockDefinition(...$block));
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
     * Whether a block has a field with show_when (since 0.91.0). Without one, visibleBlock() and
     * visibleBlocksIn() give their values back unchanged.
     */
    public function hasShowWhen(): bool
    {
        return $this->ruled() !== [];
    }

    /**
     * The fields of a block its show_when hides on the block's values (FieldVisibility::hiddenFields();
     * the source is a field of the same block). [] for an unknown block or one without show_when.
     *
     * @param array<string, mixed> $values the block's values
     * @return list<string>
     * @since 0.91.0
     */
    public function hiddenFieldsOf(string $key, array $values): array
    {
        $definition = $this->blocks[$key] ?? null;
        if ($definition === null || !isset($this->ruled()[$key])) {
            return [];
        }

        return FieldVisibility::hiddenFields($definition->fields, $values);
    }

    /**
     * A block's values as its output reads them: a field its show_when hides is null, in the block
     * and in every block nested in its blocks fields, each deciding on its own values. The renderer
     * gives a block's component these values (data-only children its template prints included);
     * the stored block is unchanged. Without show_when, $block comes back as given.
     *
     * @param array<string, mixed> $block a block ("_block", "_uid", its fields)
     * @return array<string, mixed>
     * @since 0.91.0
     */
    public function visibleBlock(array $block): array
    {
        return $this->hasShowWhen() ? $this->visible($block) : $block;
    }

    /**
     * visibleBlock() for every block of a record's blocks fields (for templates that read a record's
     * values: folio_get, folio_list, a page's record). Other values stay as given; so does a blocks
     * value that is not a list of blocks.
     *
     * @param array<string, mixed> $values a record's values
     * @param array<string, TypeFieldDefinition> $fields its type's fields
     * @return array<string, mixed>
     * @since 0.91.0
     */
    public function visibleBlocksIn(array $values, array $fields): array
    {
        if (!$this->hasShowWhen()) {
            return $values;
        }
        foreach ($fields as $name => $field) {
            if ($field->type === 'blocks' && is_array($values[$name] ?? null)) {
                $values[$name] = $this->visibleList($values[$name]);
            }
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private function visible(array $block): array
    {
        $key = is_string($block['_block'] ?? null) ? $block['_block'] : '';
        $definition = $this->blocks[$key] ?? null;
        if ($definition === null) {
            return $block;
        }
        foreach ($this->hiddenFieldsOf($key, $block) as $hidden) {
            $block[$hidden] = null;
        }
        foreach ($definition->fields as $name => $field) {
            if ($field->type === 'blocks' && is_array($block[$name] ?? null)) {
                $block[$name] = $this->visibleList($block[$name]);
            }
        }

        return $block;
    }

    /**
     * @param array<mixed> $list
     * @return array<mixed>
     */
    private function visibleList(array $list): array
    {
        return array_map(fn (mixed $child): mixed => is_array($child) ? $this->visible($child) : $child, $list);
    }

    /** @return array<string, true> */
    private function ruled(): array
    {
        if ($this->ruled === null) {
            $this->ruled = [];
            foreach ($this->blocks as $key => $definition) {
                if (FieldVisibility::rulesOf($definition->fields) !== []) {
                    $this->ruled[$key] = true;
                }
            }
        }

        return $this->ruled;
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
        // The shape of a cached BlockDefinition: a cache written before it gained $family (0.75) is rebuilt.
        $parts = ['definition:3']; // 3 (0.91): a registry built before the packages' field checks (BlockFieldChecks) is built again
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
        $this->ruled = null;
    }

    /**
     * @param class-string $class
     * @param list<\Closure(array<string, TypeFieldDefinition>): void> $checks
     */
    private static function define(string $class, array $checks = []): ?BlockDefinition
    {
        if (!class_exists($class)) {
            return null;
        }
        $reflection = new \ReflectionClass($class);
        $attribute = $reflection->getAttributes(Block::class, \ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;
        if ($attribute === null) {
            return null;
        }
        /** @var Block $block */
        $block = $attribute->newInstance();

        $fields = [];
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $fieldAttribute = $property->getAttributes(BlockField::class, \ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;
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

        self::checkOptionsFrom($block->key, $fields);
        self::checkShowWhen($block->key, $fields);
        foreach ($checks as $check) {
            try {
                $check($fields);
            } catch (\LogicException $e) {
                throw new BlockConfigException(sprintf('Block "%s": %s', $block->key, $e->getMessage()), 0, $e);
            }
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
            family: $block->family,
        );
    }

    /**
     * A select's "options_from" (since 0.90 in blocks) names another field of the same block; the
     * select's options are that block's values of it. Checked like a type's (SelectOptionsFrom), the
     * error naming the block.
     *
     * @param array<string, TypeFieldDefinition> $fields
     * @throws BlockConfigException
     */
    private static function checkOptionsFrom(string $key, array $fields): void
    {
        $types = [];
        foreach ($fields as $name => $field) {
            $types[(string) $name] = $field->type;
        }
        foreach ($fields as $name => $field) {
            if (!array_key_exists('options_from', $field->config)) {
                continue;
            }
            if ($field->type !== 'select') {
                throw new BlockConfigException(sprintf(
                    'Block "%s": field "%s": "options_from" is an option of a select field; make the field type "select" or remove it.',
                    $key,
                    $name,
                ));
            }
            try {
                SelectOptionsFrom::check($field->config, (string) $name, $types, 'block');
            } catch (\LogicException $e) {
                throw new BlockConfigException(sprintf('Block "%s": field "%s": %s', $key, $name, $e->getMessage()), 0, $e);
            }
        }
    }

    /**
     * A field's "show_when" (since 0.91) names another field of the same block, checked like a type's
     * (FieldVisibility::assertRules()): a malformed rule, an unknown field, the field itself, a
     * translatable field or a cycle fails, the error naming the block and the field.
     *
     * @param array<string, TypeFieldDefinition> $fields
     * @throws BlockConfigException
     */
    private static function checkShowWhen(string $key, array $fields): void
    {
        try {
            FieldVisibility::assertRules(
                sprintf('Block "%s"', $key),
                $fields,
                static fn (string $name): bool => $fields[$name]->translatable,
                'block',
            );
        } catch (ShowWhenException $e) {
            throw new BlockConfigException($e->getMessage(), 0, $e);
        }
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
