<?php

declare(strict_types=1);

namespace Preflow\Publishing;

/**
 * Which types publish: their records have a working copy and a published snapshot
 * (Publisher), and public reads see the snapshot (LiveContent, PublishedModels). The default,
 * SchemaPublishingTypes, reads the "publishing" flag of each type's schema; an admin can bind
 * its own rule (Folio's also publishes routed types and singletons unless they say otherwise).
 */
interface PublishingTypes
{
    public function publishes(string $type): bool;

    /** @return list<string> the keys of every type that publishes */
    public function publishing(): array;
}
