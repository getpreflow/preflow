<?php

declare(strict_types=1);

namespace Preflow\Folio\Redirects;

use Preflow\Folio\Content\FrontendResolver;
use Preflow\Routing\Redirect\RedirectTargets;

/** A redirect's record target: its public URL in the language asked for, else in its type's default language. */
final class FolioRedirectTargets implements RedirectTargets
{
    public function __construct(
        private readonly LiveRecords $live,
        private readonly FrontendResolver $resolver,
    ) {}

    public function url(string $type, string $id, string $locale): ?string
    {
        $record = $this->live->find($type, $id);
        if ($record === null) {
            return null;
        }

        return $this->resolver->urlFor($record, $locale)
            ?? $this->resolver->urlFor($record, $record->getType()->locales->default);
    }
}
