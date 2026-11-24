<?php

declare(strict_types=1);

namespace Preflow\Components;

/**
 * A component's parent on the page, known by its class and its key only: what a token names
 * (ComponentToken::encodeFor()) and what ComponentRenderer::parentRefOf() gives without building
 * the parent.
 *
 * @since 0.87.0
 */
final readonly class ParentRef
{
    /** @param class-string<Component> $class */
    public function __construct(
        public string $class,
        public string|int|null $key = null,
    ) {}

    /** $component's class and its `key` prop (an empty or non-scalar key is no key). */
    public static function of(Component $component): self
    {
        $key = $component->getProps()['key'] ?? null;

        return new self($component::class, (is_string($key) && $key !== '') || is_int($key) ? $key : null);
    }
}
