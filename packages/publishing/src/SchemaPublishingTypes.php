<?php

declare(strict_types=1);

namespace Preflow\Publishing;

use Preflow\Data\DataManager;

/**
 * The types whose schema says "publishing": true (a model JSON file, or a model class's schema).
 * A type that does not exist does not publish.
 */
final class SchemaPublishingTypes implements PublishingTypes
{
    public function __construct(
        private readonly DataManager $dm,
    ) {}

    public function publishes(string $type): bool
    {
        $registry = $this->dm->getTypeRegistry();
        if ($registry === null || !$registry->has($type)) {
            return false;
        }

        return ($registry->schema($type)['publishing'] ?? false) === true;
    }

    public function publishing(): array
    {
        return array_values(array_filter($this->dm->getTypeRegistry()?->keys() ?? [], $this->publishes(...)));
    }
}
