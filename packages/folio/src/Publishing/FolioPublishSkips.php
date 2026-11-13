<?php

declare(strict_types=1);

namespace Preflow\Folio\Publishing;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Content\FrontendRoute;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Publishing\PublishSkips;

/**
 * `publish <type>` leaves alone a never-published record that was not public before publishing
 * was turned on: its type's legacy frontend "published" field (e.g. status), while the type
 * still has that field, does not hold the published value.
 */
final class FolioPublishSkips implements PublishSkips
{
    /** @var array<string, array{0: string, 1: mixed}|null> the gate per type */
    private array $gates = [];

    public function __construct(
        private readonly TypeCatalog $catalog,
        private readonly DataManager $dm,
    ) {}

    public function reason(DynamicRecord $record): ?string
    {
        $type = $record->getType()->key;
        $gate = array_key_exists($type, $this->gates) ? $this->gates[$type] : ($this->gates[$type] = $this->gate($type));
        if ($gate === null || $record->get($gate[0]) === $gate[1]) {
            return null;
        }

        return "{$gate[0]} is not \"" . (is_scalar($gate[1]) ? (string) $gate[1] : '') . '"';
    }

    /**
     * The legacy "published" field and value, when the type still has that field.
     *
     * @return array{0: string, 1: mixed}|null
     */
    private function gate(string $type): ?array
    {
        $frontend = $this->catalog->get($type)?->frontend;
        if ($frontend === null) {
            return null;
        }
        $route = FrontendRoute::fromConfig($type, $frontend);
        $fields = $this->dm->getTypeRegistry()?->get($type)->fields ?? [];

        return isset($fields[$route->publishedField]) ? [$route->publishedField, $route->publishedValue] : null;
    }
}
