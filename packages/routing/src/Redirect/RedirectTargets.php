<?php

declare(strict_types=1);

namespace Preflow\Routing\Redirect;

interface RedirectTargets
{
    /** The record's public URL in that locale, else its default-language URL, else null (gone or not public). */
    public function url(string $type, string $id, string $locale): ?string;
}
