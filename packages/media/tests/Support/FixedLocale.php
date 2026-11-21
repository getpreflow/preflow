<?php

declare(strict_types=1);

namespace Preflow\Media\Tests\Support;

use Preflow\Core\Context\RenderContext;
use Preflow\Media\ContextLocaleProvider;

/** A settable locale for tests (the page's language changes per request). */
final class FixedLocale implements ContextLocaleProvider
{
    public function __construct(private string $current, private readonly string $default = 'en') {}

    public function set(string $locale): void
    {
        $this->current = $locale;
    }

    public function current(): string
    {
        return $this->current;
    }

    /** The language the test set, whatever the context says. */
    public function localeOf(RenderContext $ctx): string
    {
        return $this->current;
    }

    public function default(): string
    {
        return $this->default;
    }
}
