<?php

declare(strict_types=1);

namespace Preflow\Folio\Publishing;

use Preflow\Folio\Content\FrontendResolver;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Publishing\RecordAddressFields;
use Preflow\Publishing\RecordTypes;

/**
 * Folio's listings: a type is kept in a manual order when its model's "list" says "orderable": true.
 * Its address fields are the parameters of its frontend route (since 0.75).
 */
final class FolioRecordTypes implements RecordTypes, RecordAddressFields
{
    /** @param (\Closure(): ?FrontendResolver)|null $frontend the routes, asked when an address is needed */
    public function __construct(
        private readonly TypeCatalog $catalog,
        private readonly ?\Closure $frontend = null,
    ) {}

    /**
     * The fields of the type's route parameters, a catch-all ("{...path}") marked true; none
     * for a type without a frontend.
     *
     * @since 0.75
     */
    public function addressFields(string $type): array
    {
        $route = $this->frontend !== null ? ($this->frontend)()?->routeFor($type) : null;

        return array_map(static fn (string $mode): bool => $mode === 'path', $route?->addressModes() ?? []);
    }

    public function orderable(string $type): bool
    {
        return ($this->catalog->get($type)?->list['orderable'] ?? false) === true;
    }
}
