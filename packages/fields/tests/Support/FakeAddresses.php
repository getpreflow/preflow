<?php

declare(strict_types=1);

namespace Preflow\Fields\Tests\Support;

use Preflow\Fields\AssetAddresses;
use Preflow\Fields\RecordAddresses;

/** RecordAddresses and AssetAddresses from maps: "type/id/locale" => address, asset id => address. */
final class FakeAddresses implements RecordAddresses, AssetAddresses
{
    /**
     * @param array<string, string> $records
     * @param array<string, string> $assets
     */
    public function __construct(private readonly array $records, private readonly array $assets = []) {}

    public function addressOf(string $type, string $id, string $locale): ?string
    {
        return $this->records[$type . '/' . $id . '/' . $locale] ?? null;
    }

    public function assetAddressOf(string $id): ?string
    {
        return $this->assets[$id] ?? null;
    }
}
