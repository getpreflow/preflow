<?php

declare(strict_types=1);

namespace Preflow\Publishing;

use Preflow\Data\DataManager;
use Preflow\Data\TypeDefinition;
use Preflow\Data\VersionFields;

/**
 * The data layer's VersionFields for publishing: the working copies of the types that publish
 * (PublishingTypes; the type's own table, not its snapshots or revisions) keep `_version`, so a
 * save through DataManager that changes content (an app's own model save, an import) bumps it and
 * the published version tells a change; the publishing keys (Publisher::META) are no content. Set on the DataManager by PublishingServiceProvider.
 *
 * @since 0.77
 */
final class PublishingVersionFields implements VersionFields
{
    /** @var array<string, ?string> by "key|table" */
    private array $memo = [];

    /** @param \Closure(): PublishingTypes $types resolved on first use (an app or Folio binds its own) */
    public function __construct(
        private readonly \Closure $types,
        private readonly DataManager $dm,
    ) {}

    public function of(TypeDefinition $def): ?string
    {
        $key = $def->key . '|' . $def->table;
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }
        $registry = $this->dm->getTypeRegistry();
        if ($registry === null || !$registry->has($def->key)) {
            return null; // not remembered: the type may be defined later
        }
        $versioned = ($this->types)()->publishes($def->key) && $registry->get($def->key)->table === $def->table;

        return $this->memo[$key] = $versioned ? '_version' : null;
    }

    /** The publishing keys (Publisher::META): a schedule, a position, a share token are no content. */
    public function nonContent(TypeDefinition $def): array
    {
        return array_values(array_diff(Publisher::META, ['_version']));
    }
}
