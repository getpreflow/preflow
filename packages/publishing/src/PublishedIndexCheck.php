<?php

declare(strict_types=1);

namespace Preflow\Publishing;

use Preflow\Core\Boot\BootManifest;
use Preflow\Data\DataManager;

/**
 * A type that does not publish has no published copy, so a `#[PublishedIndex]` (or
 * `"published_indexes"`) on it would never make an index. That is a mistake worth stopping at boot,
 * not an index that quietly never appears. With a boot manifest the check runs when the manifest is
 * built (`preflow optimize`).
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

    /** @throws \RuntimeException naming the type and the attribute */
    public function assert(): void
    {
        $registry = $this->dm->getTypeRegistry();
        if ($registry === null) {
            return;
        }
        $check = function () use ($registry): bool {
            foreach ($registry->keys() as $type) {
                if (($registry->schema($type)['published_indexes'] ?? []) !== [] && !$this->types->publishes($type)) {
                    throw new \RuntimeException(
                        "Type \"{$type}\" declares PublishedIndex (\"published_indexes\"), but it does not publish, so it has no published copy to index: "
                        . 'turn publishing on for the type, or use Index ("indexes") for an index on its table.'
                    );
                }
            }

            return true;
        };
        if ($this->manifest === null) {
            $check();

            return;
        }
        $this->manifest->remember('publishing.published_indexes', $check, $this->manifest->isRecording() ? $registry->checkSources() : []);
    }
}
