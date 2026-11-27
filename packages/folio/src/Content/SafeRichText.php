<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Blocks\BlockRegistry;
use Preflow\Blocks\BlockTextCleaner;
use Preflow\Blocks\CoreBlockTextCleaner;
use Preflow\Blocks\Values\BlocksFieldValue;
use Preflow\Data\FieldKinds;
use Preflow\Data\Html\HtmlCleaner;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\Values\CoreValues;
use Preflow\Data\Values\StoredCleaning;
use Preflow\Folio\Field\FieldTypeRegistry;

/**
 * Rich text on its way to a template: every richtext field of a record or block, nested blocks
 * included (a blocks field's, an areas field's columns). Since 0.93 a thin delegate to the core:
 * fields() is StoredCleaning::fields() (the DataManager's, given as $cleaning: a site's bound
 * HtmlCleaner), block() is CoreBlockTextCleaner::block(). Folio itself uses those; given no
 * StoredCleaning (the 0.92 constructor), it cleans with the richtext field type's frontend
 * output, as before.
 *
 * @deprecated since 0.93.0, removed no earlier than 0.95: use DataManager::cleaning()
 *             (StoredCleaning::fields()) for a record's values and CoreBlockTextCleaner for a block.
 */
final class SafeRichText implements BlockTextCleaner
{
    private readonly StoredCleaning $cleaning;

    private readonly CoreBlockTextCleaner $core;

    public function __construct(
        FieldTypeRegistry $fieldTypes,
        BlockRegistry $blocks,
        ?StoredCleaning $cleaning = null,
    ) {
        $this->cleaning = $cleaning ?? self::cleaningOver($fieldTypes, $blocks);
        $this->core = new CoreBlockTextCleaner($this->cleaning, $blocks);
    }

    /**
     * @param array<string, mixed> $values a record's or block's values (localized or locale maps)
     * @param array<string, object> $fields field definitions with a "type" property (TypeFieldDefinition for those holding blocks)
     * @return array<string, mixed>
     */
    public function fields(array $values, array $fields): array
    {
        $definitions = [];
        foreach ($fields as $name => $field) {
            $definitions[$name] = $field instanceof TypeFieldDefinition ? $field : new TypeFieldDefinition((string) $name, (string) ($field->type ?? ''));
        }

        return $this->cleaning->fields($values, $definitions);
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    public function block(array $block): array
    {
        return $this->core->block($block);
    }

    /**
     * The 0.92 constructor's cleaning: rich text through the registry's richtext field type
     * (renderFrontend()), nested blocks and areas through the core's blocks value.
     */
    private static function cleaningOver(FieldTypeRegistry $fieldTypes, BlockRegistry $blocks): StoredCleaning
    {
        $kinds = new FieldKinds();
        CoreValues::register($kinds, static fn (): HtmlCleaner => new class ($fieldTypes) implements HtmlCleaner {
            public function __construct(private readonly FieldTypeRegistry $fieldTypes) {}

            public function clean(string $html): string
            {
                return $this->fieldTypes->get('richtext')->renderFrontend($html, []);
            }
        });
        // Cleaning walks an areas field's columns as a blocks field's lists (BlockLists::map()).
        $value = new BlocksFieldValue(static fn (): BlockRegistry => $blocks, $kinds);
        $kinds->value('blocks', $value);
        $kinds->value('areas', $value);

        return new StoredCleaning($kinds);
    }
}
