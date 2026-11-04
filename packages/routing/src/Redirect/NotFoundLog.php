<?php

declare(strict_types=1);

namespace Preflow\Routing\Redirect;

interface NotFoundLog
{
    /** Notes a 404 for this path (no query string) and locale. */
    public function record(string $path, string $locale, \DateTimeImmutable $at): void;
}
