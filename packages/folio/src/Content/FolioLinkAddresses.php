<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Data\TypeRegistry;
use Preflow\Fields\AssetAddresses;
use Preflow\Fields\RecordAddresses;
use Preflow\Media\MediaLibrary;
use Preflow\Publishing\LiveContent;

/**
 * preflow/fields' RecordAddresses on a Folio site (0.91): a record's address is its public URL in
 * the locale (FrontendResolver::urlFor()), read through LiveContent, so a visitor's page finds only
 * published records (an unpublished or deleted one has no address, its link no href) and a preview
 * finds the working copy. A file's address is the media library's URL of the asset.
 *
 * FolioServiceProvider binds it unless the site bound its own RecordAddresses.
 */
final class FolioLinkAddresses implements RecordAddresses, AssetAddresses, \Preflow\Fields\AssetImages
{
    /** @param (\Closure(): ?MediaLibrary)|null $media the media library, when it has storage */
    public function __construct(
        private readonly FrontendResolver $frontend,
        private readonly LiveContent $live,
        private readonly TypeRegistry $types,
        private readonly ?\Closure $media = null,
    ) {}

    public function addressOf(string $type, string $id, string $locale): ?string
    {
        if (!$this->types->has($type) || $this->frontend->routeFor($type) === null) {
            return null;
        }
        $record = $this->live->find($type, $id);

        return $record !== null ? $this->frontend->urlFor($record, $locale) : null;
    }

    public function assetAddressOf(string $id): ?string
    {
        $library = $this->media !== null ? ($this->media)() : null;
        $asset = $library?->find($id);

        return $asset !== null ? $library->url($asset) : null;
    }

    /** An embed's poster check: an image of the library; null without library storage (shape only then). */
    public function isImage(string $id): ?bool
    {
        $library = $this->media !== null ? ($this->media)() : null;
        if ($library === null) {
            return null;
        }
        $asset = $library->find($id);

        return $asset !== null && $asset->kind() === 'image';
    }
}
