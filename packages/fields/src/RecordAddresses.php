<?php

declare(strict_types=1);

namespace Preflow\Fields;

/**
 * Where a record lives on the site, for a link that points at it (Link::href()). Optional: with
 * nothing bound, a record link renders without an href. Folio binds one that answers with the
 * record's current public address (FrontendResolver::urlFor()); a site without Folio binds its own.
 *
 * Bind it as `Preflow\Fields\RecordAddresses` in the container. It may also implement
 * AssetAddresses, for links to files.
 *
 * @since 0.91.0
 */
interface RecordAddresses
{
    /**
     * The address of the record in $locale ("/about", "/de/ueber-uns", or an absolute URL), as
     * the page that links to it shows it; null when the record is not there for a visitor: deleted,
     * unpublished, not visible in that locale, or of a type without public addresses.
     */
    public function addressOf(string $type, string $id, string $locale): ?string;
}
