<?php

declare(strict_types=1);

namespace Preflow\Folio\Media;

use Preflow\Data\DataManager;
use Preflow\Media\MediaConfig;

/**
 * Whether the media library can be used on this site: the storage driver config/media.php names
 * ("json" unless set) is one config/data.php configures. A site may have no media; every Folio
 * place that reads the library asks here and degrades (the assets screen explains, the search,
 * the dashboard tile and the asset field leave the library out) instead of failing.
 *
 * @since 0.84.0
 */
final class MediaAvailability
{
    public function __construct(
        private readonly MediaConfig $media,
        private readonly DataManager $dm,
    ) {}

    public function usable(): bool
    {
        return in_array($this->media->storage, $this->dm->driverNames(), true);
    }

    /** The storage driver name the library needs (for the message that says what is missing). */
    public function driver(): string
    {
        return $this->media->storage;
    }
}
