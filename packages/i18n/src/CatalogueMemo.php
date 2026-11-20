<?php

declare(strict_types=1);

namespace Preflow\I18n;

/**
 * The translator's text cache for one render context: the locale its texts are in, whether its
 * surface has overrides on, and the texts resolved so far.
 *
 * @internal Translator
 *
 * @since 0.81.0
 */
final class CatalogueMemo
{
    /** @var array<string, string|null> "{locale}\0{key}" => the text (null: none) */
    public array $texts = [];

    public function __construct(
        public readonly string $locale,
        public readonly bool $overrides,
    ) {}
}
