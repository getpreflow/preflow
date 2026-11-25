<?php

declare(strict_types=1);

namespace Preflow\Folio\Field;

use Preflow\Blocks\BlockDefinition;

/**
 * Optional for a field type whose rules depend on the other fields of the block it sits in (a
 * select with "options_from" reads its source field of the same block through that field's type):
 * HasRecordRules for a block. Where a block's fields are validated (BlocksFieldType::validateBlocks(),
 * with the whole block as the data), blockRules() is used instead of FieldType::rules().
 *
 * @since 0.90.0
 */
interface HasBlockRules
{
    /**
     * @param array<string, mixed> $config the field's config
     * @return list<string|\Preflow\Validation\ValidationRule>
     */
    public function blockRules(array $config, BlockDefinition $block, FieldTypeRegistry $fieldTypes): array;
}
