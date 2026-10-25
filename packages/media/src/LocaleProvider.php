<?php

declare(strict_types=1);

namespace Preflow\Media;

/** The language assets render in (alt text): the current one, and the default to fall back to. */
interface LocaleProvider
{
    public function current(): string;

    public function default(): string;
}
