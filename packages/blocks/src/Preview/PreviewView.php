<?php

declare(strict_types=1);

namespace Preflow\Blocks\Preview;

/**
 * One view of a type's preview: its key (`default` for the short form), the label the view switch
 * shows (null: none given; a key-shaped label is translated like other declared labels) and the
 * component that renders a record for it (a name in the component map, or a class extending
 * Preflow\Components\Component, as the template function component() accepts).
 *
 * @since 0.94.0
 */
final readonly class PreviewView
{
    public function __construct(
        public string $key,
        public ?string $label,
        public string $component,
    ) {}
}
