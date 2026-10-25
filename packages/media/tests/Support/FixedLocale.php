<?php

declare(strict_types=1);

namespace Preflow\Media\Tests\Support;

use Preflow\Media\LocaleProvider;

/** A settable locale for tests (the page's language changes per request). */
final class FixedLocale implements LocaleProvider
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

    public function default(): string
    {
        return $this->default;
    }
}
