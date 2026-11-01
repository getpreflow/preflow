<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Data\Locales;

/**
 * The locale content is rendered in for the current request (the page's
 * locale on the frontend, the editing locale in previews). Field types that
 * render referenced records (relation, matrix) follow it.
 */
final class ContentLocale
{
    private ?string $locale = null;

    public function __construct(
        private readonly Locales $locales,
    ) {}

    public function set(string $locale): void
    {
        $this->locale = $this->locales->has($locale) ? $locale : $this->locales->default;
    }

    public function get(): string
    {
        return $this->locale ?? $this->locales->default;
    }

    /**
     * Run $render and put this locale back afterwards (also when it throws), so
     * an internal render (a GEO Markdown view) can't change the request's.
     *
     * @template T
     * @param callable(): T $render
     * @return T
     */
    public function scoped(callable $render): mixed
    {
        $locale = $this->locale;
        try {
            return $render();
        } finally {
            $this->locale = $locale;
        }
    }

    public function locales(): Locales
    {
        return $this->locales;
    }
}
