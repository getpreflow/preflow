<?php

declare(strict_types=1);

/**
 * The asset library, image tag, value classes and image backends moved to preflow/media; the record users
 * (RecordUser, RecordUserProvider) to preflow/auth (Preflow\Auth\Record); the publishing classes and
 * LiveContent to preflow/publishing (Preflow\Publishing, 0.71). The old names keep working for one
 * release; use the new names. A config/auth.php naming the old provider logs a deprecation
 * (FolioServiceProvider). Loaded by Composer ("autoload.files").
 *
 * Preflow\Folio\Publishing\Publisher is no alias but a subclass (Publishing/Publisher.php): it still takes
 * the constructor arguments of before 0.71. It goes in 0.72, with these aliases.
 */

$aliases = [
    'Preflow\Folio\Assets\Asset' => 'Preflow\Media\Asset',
    'Preflow\Folio\Assets\Variant' => 'Preflow\Media\Variant',
    'Preflow\Folio\Assets\AssetUploadException' => 'Preflow\Media\AssetUploadException',
    'Preflow\Folio\Assets\AssetLibrary' => 'Preflow\Media\MediaLibrary',
    'Preflow\Folio\Assets\ImageTag' => 'Preflow\Media\ImageTag',
    'Preflow\Folio\Assets\VariantGenerator' => 'Preflow\Media\Image\VariantGenerator',
    'Preflow\Folio\Assets\ImageWidths' => 'Preflow\Media\Image\ImageWidths',
    'Preflow\Folio\Assets\Image\ImageBackend' => 'Preflow\Media\Image\ImageBackend',
    'Preflow\Folio\Assets\Image\ImageBackends' => 'Preflow\Media\Image\ImageBackends',
    'Preflow\Folio\Assets\Image\GdBackend' => 'Preflow\Media\Image\GdBackend',
    'Preflow\Folio\Assets\Image\ImagickBackend' => 'Preflow\Media\Image\ImagickBackend',
    'Preflow\Folio\Assets\Image\Orientation' => 'Preflow\Media\Image\Orientation',
    'Preflow\Folio\Users\RecordUser' => 'Preflow\Auth\Record\RecordUser',
    'Preflow\Folio\Users\RecordUserProvider' => 'Preflow\Auth\Record\RecordUserProvider',
    'Preflow\Folio\Block\Block' => 'Preflow\Blocks\Block',
    'Preflow\Folio\Block\BlockComponent' => 'Preflow\Blocks\BlockComponent',
    'Preflow\Folio\Block\BlockConfigException' => 'Preflow\Blocks\BlockConfigException',
    'Preflow\Folio\Block\BlockDefinition' => 'Preflow\Blocks\BlockDefinition',
    'Preflow\Folio\Block\BlockField' => 'Preflow\Blocks\BlockField',
    'Preflow\Folio\Block\BlockRegistry' => 'Preflow\Blocks\BlockRegistry',
    'Preflow\Folio\Block\BlockRenderer' => 'Preflow\Blocks\BlockRenderer',
    'Preflow\Folio\Publishing\PublishedModels' => 'Preflow\Publishing\PublishedModels',
    'Preflow\Folio\Publishing\PublishState' => 'Preflow\Publishing\PublishState',
    'Preflow\Folio\Publishing\PublishedCopies' => 'Preflow\Publishing\PublishedCopies',
    'Preflow\Folio\Publishing\ShadowTypes' => 'Preflow\Publishing\ShadowTypes',
    'Preflow\Folio\Publishing\Revision' => 'Preflow\Publishing\Revision',
    'Preflow\Folio\Publishing\Scheduler' => 'Preflow\Publishing\Scheduler',
    'Preflow\Folio\Publishing\VersionConflict' => 'Preflow\Publishing\VersionConflict',
    'Preflow\Folio\Publishing\PreviewLinks' => 'Preflow\Publishing\PreviewLinks',
    'Preflow\Folio\Publishing\PreviewTarget' => 'Preflow\Publishing\PreviewTarget',
    'Preflow\Folio\Content\LiveContent' => 'Preflow\Publishing\LiveContent',
];

foreach ($aliases as $old => $new) {
    if (!class_exists($old, false)) {
        class_alias($new, $old);
    }
}

unset($aliases, $old, $new);
