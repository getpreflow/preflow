<?php

declare(strict_types=1);

/**
 * The asset library, image tag, value classes and image backends moved to preflow/media; the record users
 * (RecordUser, RecordUserProvider) to preflow/auth (Preflow\Auth\Record). The old names keep working for one
 * release; use the new names. A config/auth.php naming the old provider logs a deprecation (FolioServiceProvider).
 * Loaded by Composer ("autoload.files").
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
];

foreach ($aliases as $old => $new) {
    if (!class_exists($old, false)) {
        class_alias($new, $old);
    }
}

unset($aliases, $old, $new);
