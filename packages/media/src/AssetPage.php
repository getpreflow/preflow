<?php

declare(strict_types=1);

namespace Preflow\Media;

/** One page of MediaLibrary::query(): its assets, the number of all matches and the page shown. */
final readonly class AssetPage
{
    /** @param list<Asset> $items */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {}
}
