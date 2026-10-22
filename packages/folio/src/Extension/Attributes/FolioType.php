<?php

declare(strict_types=1);

namespace Preflow\Folio\Extension\Attributes;

/**
 * Customizes one content type's admin: its methods carry #[FormPanel], #[RecordAction],
 * #[ListColumn] and the save/delete hooks (#[BeforeSave], #[AfterSave], #[BeforeDelete],
 * #[AfterDelete]). The class is built from the container. An unknown type key is a boot error.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class FolioType
{
    public function __construct(
        public readonly string $key,
    ) {}
}
