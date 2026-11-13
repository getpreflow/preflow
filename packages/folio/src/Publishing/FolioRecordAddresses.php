<?php

declare(strict_types=1);

namespace Preflow\Folio\Publishing;

use Preflow\Core\PageCache\PageCache;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Content\FrontendResolver;
use Preflow\Folio\Redirects\RecordAddresses;
use Preflow\Folio\Redirects\RemovalTarget;
use Preflow\Publishing\RecordAddresses as PublishingAddresses;
use Preflow\Publishing\RemovalTarget as PublishingTarget;

/**
 * The public addresses of Folio's records: the address book behind the automatic redirects
 * (RecordAddresses, when redirects are on) and the page cache, told the address tags
 * (FrontendResolver::addressTags()) of the pages a publish, unpublish or delete leaves and takes.
 */
final class FolioRecordAddresses implements PublishingAddresses
{
    public function __construct(
        private readonly ?RecordAddresses $addresses,
        private readonly ?PageCache $pageCache,
        private readonly ?FrontendResolver $resolver,
    ) {}

    public function sync(string $type, string $id, ?DynamicRecord $previousLive): bool
    {
        return $this->addresses?->sync($type, $id, $previousLive) ?? false;
    }

    public function removed(string $type, string $id, ?PublishingTarget $target): void
    {
        $this->addresses?->removed($type, $id, $target instanceof RemovalTarget ? $target : null);
    }

    /** The pages of the record itself follow its record tag anyway. */
    public function changed(?DynamicRecord $before, ?DynamicRecord $after): void
    {
        if ($this->pageCache === null || $this->resolver === null) {
            return;
        }
        $tags = array_values(array_unique([...$this->resolver->addressTags($before), ...$this->resolver->addressTags($after)]));
        if ($tags !== []) {
            $this->pageCache->changed(...$tags);
        }
    }
}
