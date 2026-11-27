<?php

declare(strict_types=1);

namespace Preflow\Folio\Publishing;

use Preflow\Blocks\Layouts\PageLayouts;
use Preflow\Data\TypeDefinition;
use Preflow\Folio\Storage\TableSync;
use Preflow\Publishing\RecordColumns;

/**
 * The columns Folio writes besides a type's fields (translation fingerprints, sources and machine
 * marks, the SEO section, …, and since 0.92 a type with page layouts its "_layout" and "_areas"):
 * the tables of publishing types get them as Folio's own tables do (TableSync::columnsFor()).
 */
final class FolioRecordColumns implements RecordColumns
{
    /** @param (\Closure(): ?PageLayouts)|null $layouts the types' page layouts, asked on use (bound in preflow/blocks' boot) */
    public function __construct(private readonly ?\Closure $layouts = null) {}

    public function columns(TypeDefinition $def): array
    {
        return TableSync::columnsOf($def, $this->layouts !== null ? ($this->layouts)() : null);
    }
}
