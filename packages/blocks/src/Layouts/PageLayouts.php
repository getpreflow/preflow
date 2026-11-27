<?php

declare(strict_types=1);

namespace Preflow\Blocks\Layouts;

use Preflow\Blocks\BlockRegistry;
use Preflow\Data\DynamicRecord;
use Preflow\Data\LocaleValue;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeRegistry;

/**
 * Page layouts on types: a type with "layouts" (TypeLayouts) stores on each record the layout key
 * in "_layout" and the area blocks in "_areas" ({area: [blocks…]}, like a blocks field per area).
 *
 * A page's layout is resolved in this order: its pin (when that layout exists), the stored key
 * (when the type allows it and it exists), the type's default (when it exists), the first allowed
 * layout that exists, else none. A stored or pinned key that could not be used is a fallback: the
 * page renders with the layout found instead, and its blocks stay where they are (Placement).
 *
 * @since 0.92.0
 */
final class PageLayouts
{
    public const string LAYOUT = '_layout';
    public const string AREAS = '_areas';
    public const array COLUMNS = [self::LAYOUT, self::AREAS];

    /** @var array<string, TypeLayouts|null> */
    private array $types = [];

    public function __construct(
        private readonly LayoutRegistry $layouts,
        private readonly TypeRegistry $registry,
        private readonly ?BlockRegistry $blocks = null,
    ) {}

    /**
     * The type's "layouts"; null for a type without it, or one the registry does not have.
     *
     * @throws LayoutConfigException when the type's "layouts" cannot work
     */
    public function forType(string $type): ?TypeLayouts
    {
        if (array_key_exists($type, $this->types)) {
            return $this->types[$type];
        }
        // Not remembered while the registry lacks the type: a provider may define it later.
        if (!$this->registry->has($type)) {
            return null;
        }

        return $this->types[$type] = TypeLayouts::fromSchema($type, $this->registry->schema($type));
    }

    /**
     * The layouts an editor may choose for a page of the type: the allowed ones a class declares,
     * in "allowed" order.
     *
     * @return list<AreaLayout>
     */
    public function selectable(string $type): array
    {
        $selectable = [];
        foreach ($this->forType($type)?->allowed ?? [] as $key) {
            $layout = $this->layouts->get($key);
            if ($layout !== null) {
                $selectable[] = $layout;
            }
        }

        return $selectable;
    }

    /**
     * The area names the type's layouts declare: those of the layouts an editor may choose and of
     * the pinned ones (each class that exists), in that order, each once. An area by one of these
     * names may hold blocks on any page of the type (unplaced while its layout lacks it).
     *
     * @return list<string>
     */
    public function declaredAreas(string $type): array
    {
        $layouts = $this->selectable($type);
        foreach ($this->forType($type)->pinned ?? [] as $key) {
            $layout = $this->layouts->get($key);
            if ($layout !== null) {
                $layouts[] = $layout;
            }
        }
        $names = [];
        foreach ($layouts as $layout) {
            foreach ($layout->areaNames() as $name) {
                $names[$name] = true;
            }
        }

        return array_keys($names);
    }

    /** The stored record's layout; null for a type without "layouts". */
    public function resolve(DynamicRecord $record): ?ResolvedLayout
    {
        return $this->resolveValues($record->getType(), $record->toArray());
    }

    /**
     * The layout for a record's values that may not be stored (a draft, the preview of the form);
     * null for a type without "layouts".
     *
     * @param array<string, mixed> $values
     */
    public function resolveValues(TypeDefinition $def, array $values): ?ResolvedLayout
    {
        $type = $this->forType($def->key);
        if ($type === null) {
            return null;
        }
        $slug = self::slugOf($def, $type, $values);
        $pin = $type->pinFor($slug);
        $stored = $values[self::LAYOUT] ?? null;
        $stored = is_string($stored) && $stored !== '' ? $stored : null;

        $layout = $pin !== null ? $this->layouts->get($pin) : null;
        $fallback = $pin !== null && $layout === null;
        if ($layout === null && $stored !== null) {
            $layout = in_array($stored, $type->allowed, true) ? $this->layouts->get($stored) : null;
            $fallback = $fallback || $layout === null;
        }
        $layout ??= $this->layouts->get($type->default) ?? ($this->selectable($def->key)[0] ?? null);

        return new ResolvedLayout(
            $layout,
            $stored,
            $pin !== null,
            $pin !== null ? $slug : null,
            $fallback,
            Placement::of($layout, $values[self::AREAS] ?? null),
            $this->blocks,
        );
    }

    /**
     * Checks the "layouts" of every type the registry has (the boot check; kept in the boot
     * manifest).
     *
     * @throws LayoutConfigException naming the type
     */
    public function assertTypes(): void
    {
        foreach ($this->registry->keys() as $type) {
            $this->forType($type);
        }
    }

    /**
     * The default-locale value of the type's slug field in $values (a locale map, its JSON text,
     * or plain text); null when there is none.
     *
     * @param array<string, mixed> $values
     */
    public static function slugOf(TypeDefinition $def, TypeLayouts $type, array $values): ?string
    {
        $slug = LocaleValue::pick($values[$type->slugField] ?? null, $def->locales, $def->locales->default);

        return is_scalar($slug) && (string) $slug !== '' ? (string) $slug : null;
    }
}
