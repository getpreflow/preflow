<?php

declare(strict_types=1);

namespace Preflow\I18n;

/**
 * The translator's text cache per text locale and overrides switch (shared by every render
 * context with the same two): the locale its texts are in, whether overrides are on, and the
 * texts resolved so far.
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
