<?php

declare(strict_types=1);

namespace Preflow\Folio\Redirects;

use Preflow\Folio\Content\FrontendResolver;
use Preflow\Folio\Content\RecordId;
use Preflow\Routing\Redirect\RedirectUrl;

/**
 * Where the old links of a record that leaves the site go ("Send its old links to…"): another
 * record (type and id; its address is resolved when a link is followed) or a URL (an absolute
 * http(s) URL, or a path on this site).
 */
final readonly class RemovalTarget implements \Preflow\Publishing\RemovalTarget
{
    private function __construct(
        public ?string $type,
        public ?string $id,
        public ?string $url,
    ) {}

    /**
     * The posted choice: "type:id" of a type with a frontend route (checked against $routes when
     * given), an http(s) URL with a host, or a path on this site ("/…", never "//host" or "/\host").
     * Anything else (nothing chosen, other schemes, malformed input) is null.
     */
    public static function parse(mixed $posted, ?FrontendResolver $routes = null): ?self
    {
        if (!is_string($posted)) {
            return null;
        }
        $posted = trim($posted);
        if ($posted === '' || strlen($posted) > RedirectUrl::MAX_LENGTH || preg_match('/[\x00-\x20\x7f]/', $posted) === 1) {
            return null;
        }

        // A URL on the web or a path on this site: RedirectUrl decides, as for every redirect writer.
        if (preg_match('#^https?://#i', $posted) === 1 || str_starts_with($posted, '/')) {
            return RedirectUrl::isValid($posted) ? new self(null, null, $posted) : null;
        }

        if (preg_match('/^([a-z0-9_-]+):(.+)$/i', $posted, $m) === 1 && RecordId::usable($m[2]) && !str_contains($m[2], ':')) {
            if ($routes !== null && $routes->routeFor($m[1]) === null) {
                return null;
            }

            return new self($m[1], $m[2], null);
        }

        return null;
    }

    /** A record target (not checked: SiteTarget::final() tells whether it is live). */
    public static function record(string $type, string $id): self
    {
        return new self($type, $id, null);
    }

    public function isRecord(string $type, string $id): bool
    {
        return $this->type === $type && $this->id === $id;
    }
}
