<?php

declare(strict_types=1);

namespace Preflow\Publishing;

/**
 * How RecordWriter saves a record.
 *
 * - $by: who saves it (a user id), stored as the publisher of a publish.
 * - $mayPublish: whether the one saving may publish. A save by someone who may not cancels a
 *   pending schedule (_publish_at), so their changes never go live unreviewed.
 * - $publish: publish the record after its draft is saved (a type that publishes); needs $mayPublish.
 * - $loadedVersion: the _version the editor loaded; a save landing in between makes this one a
 *   VersionConflict. null skips the check.
 * - $publishAt: the schedule. null keeps the stored one, '' clears it, a "Y-m-d\TH:i" time in the
 *   app's zone sets it; a past or malformed time clears it (a mistake, or a stale tab re-sending a
 *   schedule that already ran). Only with $mayPublish.
 */
final readonly class SaveOptions
{
    public function __construct(
        public ?string $by = null,
        public bool $mayPublish = true,
        public bool $publish = false,
        public ?int $loadedVersion = null,
        public ?string $publishAt = null,
    ) {
        if ($publish && !$mayPublish) {
            throw new \InvalidArgumentException('A save that may not publish cannot publish.');
        }
    }
}
