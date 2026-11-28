<?php

declare(strict_types=1);

namespace Preflow\Blocks\Preview;

use Preflow\Components\Component;
use Preflow\Components\ComponentMap;
use Preflow\Data\TypeRegistry;

/**
 * The "preview" option of the app's types (TypePreview), read from the type registry, and the boot
 * check that every view's component exists: a name in the component map, or a class extending
 * Preflow\Components\Component (what the template function component() accepts). Nothing is found
 * by name convention: the type names its components.
 *
 * A type with a route may have a preview too (the route's preview wins in Folio; these views add to
 * it).
 *
 * @since 0.94.0
 */
final class TypePreviews
{
    /** @var array<string, TypePreview|null> */
    private array $read = [];

    public function __construct(
        private readonly TypeRegistry $types,
        private readonly ComponentMap $components,
    ) {}

    /**
     * The type's preview; null for a type without "preview", or one the registry does not have.
     *
     * @throws PreviewConfigException when the type's "preview" has a shape that cannot work
     */
    public function forType(string $type): ?TypePreview
    {
        if (array_key_exists($type, $this->read)) {
            return $this->read[$type];
        }
        // Not remembered while the registry lacks the type: a provider may define it later.
        if (!$this->types->has($type)) {
            return null;
        }

        return $this->read[$type] = TypePreview::fromSchema($type, $this->types->schema($type));
    }

    /**
     * Checks the "preview" of every type the registry has: its shape, and that each view's
     * component exists (the boot check `blocks.type_previews`; kept in the boot manifest).
     *
     * @throws PreviewConfigException naming the type, the view and what to do
     */
    public function assertTypes(): void
    {
        foreach ($this->types->keys() as $type) {
            foreach ($this->forType($type)->views ?? [] as $view) {
                $this->assertComponent($type, $view);
            }
        }
    }

    /** @throws PreviewConfigException */
    private function assertComponent(string $type, PreviewView $view): void
    {
        $name = $view->component;
        if (isset($this->components->classes[$name])) {
            return;
        }
        $prefix = sprintf('Type "%s": preview view "%s" ', $type, $view->key);
        if (class_exists($name)) {
            if (is_subclass_of($name, Component::class)) {
                return;
            }
            throw new PreviewConfigException($prefix . sprintf('names the class "%s", which is no component. Extend %s, or name an existing component.', $name, Component::class));
        }
        $create = str_contains($name, '\\')
            ? 'Create the class, extending ' . Component::class
            : sprintf('Create app/Components/%1$s/%1$s.php', $name);

        throw new PreviewConfigException($prefix . sprintf('names the component "%s", which does not exist. %s, or name an existing component.', $name, $create));
    }
}
