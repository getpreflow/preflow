<?php

declare(strict_types=1);

namespace Preflow\Folio\Extension\Attributes;

/**
 * Marks a Folio extension: a class extending Preflow\Folio\Extension\Extension.
 * $translations: the translation group (declared in the package's composer.json,
 * extra.preflow.translations) holding the labels of its models: {group}.types.{type}.label,
 * {group}.fields.{type}.{field}.label, … (Preflow\Data\ModelLabelKeys). Null: the site's
 * group "site", as for the site's own model files.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class FolioExtension
{
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $description = '',
        public readonly string $icon = 'ui-puzzle',
        public readonly string $version = '1.0.0',
        public readonly ?string $translations = null,
    ) {}
}
