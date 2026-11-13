<?php

declare(strict_types=1);

namespace Preflow\Folio\Field;

/**
 * A field that is a parameter of the record's public address ("/jobs/{slug}"): the string field shows
 * the static path before it as a mono prefix ("/jobs/") and a copy button that copies the whole
 * address, $before . value . $after (the site's address from app.url, the language prefix, the rest of
 * the route). $before and $after are null when the address cannot be put together from the typed value
 * alone (another parameter in the route): then there is no copy button.
 *
 * @since 0.75
 */
final readonly class AddressAffix
{
    public function __construct(
        /** "segment" (one part) or "path" (a catch-all: parts with slashes) */
        public string $mode,
        /** The static path before the value, as shown: "/" or "/jobs/". */
        public string $prefix,
        public ?string $before = null,
        public ?string $after = null,
        /** The route's home value: that value's address is the site's root ("/"). */
        public ?string $home = null,
        /** The root address the home value copies as. */
        public ?string $homeAddress = null,
    ) {}
}
