<?php

declare(strict_types=1);

namespace Preflow\Blocks;

use Preflow\Data\Locales;

/**
 * The BlockLocale without an app of its own: the request's current locale (the i18n
 * Translator's, when the app has one) when it is a content locale, else the default.
 * It never changes the locale, so scoped() only runs the render.
 */
final class DefaultBlockLocale implements BlockLocale
{
    /** @param \Closure(): ?string $current the current locale, null without one */
    public function __construct(
        private readonly Locales $locales,
        private readonly \Closure $current,
    ) {}

    public function get(): string
    {
        $locale = ($this->current)();

        return is_string($locale) && $this->locales->has($locale) ? $locale : $this->locales->default;
    }

    public function locales(): Locales
    {
        return $this->locales;
    }

    public function scoped(callable $render): mixed
    {
        return $render();
    }
}
