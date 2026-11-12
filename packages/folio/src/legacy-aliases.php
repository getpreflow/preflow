<?php

declare(strict_types=1);

/**
 * The asset library, image tag, value classes and image backends moved to preflow/media; the record users
 * (RecordUser, RecordUserProvider) to preflow/auth (Preflow\Auth\Record); preflow/rbac's admin classes into
 * Folio (Preflow\Folio\Rbac). The old names keep working for one release; use the new names. A config/auth.php
 * naming the old provider logs a deprecation (FolioServiceProvider). Loaded by Composer ("autoload.files").
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

// preflow/rbac's admin classes moved into Folio (0.69, its built-in extension "rbac"); their old names keep
// working for one release. Code naming one first gets it here; each class file names its old name too when
// it loads, so a type-hint of the old name (a site's Types\StaffRules) accepts the object either way.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Preflow\\Rbac\\') && in_array(substr($class, 13), ['Extension', 'NewPermissions', 'NewPermissionsScreen', 'Fields\\PermissionsFieldType', 'Fields\\RolesFieldType', 'Types\\RoleRules', 'Types\\StaffAdmin', 'Types\\StaffRules'], true)) {
        class_exists('Preflow\\Folio\\Rbac\\' . substr($class, 13)); // its file declares the old name
    }
});
