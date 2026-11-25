<?php

declare(strict_types=1);

namespace Preflow\Publishing;

use Preflow\Core\Boot\BootManifest;
use Preflow\Data\DataManager;

/**
 * A type that does not publish has no published copy, so a `#[PublishedIndex]` (or
 * `"published_indexes"`) on it would never make an index. That is a mistake worth stopping,
 * not an index that quietly never appears. It runs where every type's schema is read anyway: when the
 * publishing tables are synced (`preflow migrate`) and when a boot manifest is built (`preflow optimize`),
 * never per request.
 *
 * @since 0.89.0
 */
final class PublishedIndexCheck
{
    public function __construct(
        private readonly DataManager $dm,
        private readonly PublishingTypes $types,
        private readonly ?BootManifest $manifest = null,
    ) {}

    /**
     * Reads every type's schema, so it is not run on every request: `preflow migrate` (PublishingTables)
     * and the boot manifest's build (assertWhenBuilding()) run it.
     *
     * @throws \RuntimeException naming the type and the attribute
     */
    public function assert(): void
    {
        $registry = $this->dm->getTypeRegistry();
        if ($registry === null) {
            return;
        }
        foreach ($registry->keys() as $type) {
            if (($registry->schema($type)['published_indexes'] ?? []) !== [] && !$this->types->publishes($type)) {
                throw new \RuntimeException(
                    "Type \"{$type}\" declares PublishedIndex (\"published_indexes\"), but it does not publish, so it has no published copy to index: "
                    . 'turn publishing on for the type, or use Index ("indexes") for an index on its table.'
                );
            }
        }
    }

    /**
     * The check as part of building a boot manifest (`preflow optimize`); a boot without one, and one
     * that loads one, do nothing here.
     *
     * @throws \RuntimeException as assert()
     */
    public function assertWhenBuilding(): void
    {
        $registry = $this->dm->getTypeRegistry();
        if ($registry === null || $this->manifest === null || !$this->manifest->isRecording()) {
            return;
        }
        $this->manifest->remember('publishing.published_indexes', function (): bool {
            $this->assert();

            return true;
        }, $registry->sources());
    }
}
