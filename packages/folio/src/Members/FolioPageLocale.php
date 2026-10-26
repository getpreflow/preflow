<?php

declare(strict_types=1);

namespace Preflow\Folio\Members;

use Preflow\Folio\Content\ContentLocale;
use Preflow\Members\PageLocale;

/** A member page's locale is Folio's content locale too, so the site layout renders its content in it. */
final class FolioPageLocale implements PageLocale
{
    public function __construct(
        private readonly ContentLocale $contentLocale,
    ) {}

    public function set(string $locale): void
    {
        $this->contentLocale->set($locale);
    }
}
