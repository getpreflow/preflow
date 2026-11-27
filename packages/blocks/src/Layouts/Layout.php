<?php

declare(strict_types=1);

namespace Preflow\Blocks\Layouts;

/**
 * Marks a class as a page layout. "key" is the stable storage identifier; "template" is a template
 * name the site's engine resolves. Its areas are the class's public array properties carrying #[Area],
 * in declaration order.
 *
 * @since 0.92.0
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Layout
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $template,
        public readonly ?string $description = null,
        public readonly ?string $icon = null,
    ) {}
}
