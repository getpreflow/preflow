<?php

declare(strict_types=1);

namespace Preflow\Fields\Tests\Support;

use Preflow\Fields\RecordAddresses;

/** Addresses that know records only (no AssetAddresses). */
final class RecordsOnly implements RecordAddresses
{
    public function addressOf(string $type, string $id, string $locale): ?string
    {
        return '/x';
    }
}
