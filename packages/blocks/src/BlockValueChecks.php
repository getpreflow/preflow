<?php

declare(strict_types=1);

namespace Preflow\Blocks;

use Preflow\Blocks\Layouts\AreaLayoutSets;
use Preflow\Blocks\Layouts\PageLayouts;
use Preflow\Data\FieldKinds;
use Preflow\Data\FieldLabels;
use Preflow\Data\FieldVisibility;
use Preflow\Data\LocaleValue;
use Preflow\Data\Locales;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\Validation\SelectRules;
use Preflow\Validation\ValidationRule;
use Preflow\Validation\ValidatorFactory;

/**
 * The values inside block lists, checked as a record's own fields are: every block list of every
 * field (BlockLists) and, for a type with page layouts, each area of "_areas" the layout shows
 * (an area it does not show is not checked: it is not shown). Each block is checked on its own
 * values, in the default language: each field's "validate" ("required" included), a select's
 * options (SelectRules, "options_from" reading the same block), the field type's checks
 * (FieldKinds::rules()) and the rules an adapter adds (extraRules()). A field its show_when hides
 * is not checked, nor is a field of the record its show_when hides or that the caller gives no
 * rules ([]: Folio's read-only fields). Lists nest to any depth; a block no class declares (any
 * more) is skipped.
 *
 * An areas field (0.92) is checked in the areas its layout shows, as a page's areas are.
 *
 * Error keys: "<field>.<uid>.<leaf>", nested "<field>.<uid>.<child>.<cuid>.<leaf>", page areas
 * "_areas.<area>.<uid>.<leaf>", an areas field "<field>.areas.<area>.<uid>.<leaf>".
 * BlocksServiceProvider runs these on every validated write of a typed record (a RecordRules
 * check), so Folio, imports, seeds and REST refuse the same values.
 *
 * @since 0.92.0
 */
final class BlockValueChecks
{
    /** @var list<\Closure(TypeFieldDefinition, BlockDefinition): list<string|ValidationRule>> */
    private array $extraRules = [];

    public function __construct(
        private readonly BlockRegistry $blocks,
        private readonly FieldKinds $kinds,
        private readonly ValidatorFactory $validators,
        private readonly ?PageLayouts $layouts = null,
        /** @var (\Closure(): ?FieldLabels)|null names a select's source in its message (DataManager::fieldLabels()) */
        private readonly ?\Closure $labels = null,
        /** The area layout sets (0.92): an areas field's blocks are checked in the areas its layout shows only. */
        private readonly ?AreaLayoutSets $sets = null,
    ) {}

    /**
     * Rules an adapter adds for a field of a block (Folio: its field types' rules that read the
     * block, HasBlockRules), after the field's own and its type's.
     *
     * @param \Closure(TypeFieldDefinition $field, BlockDefinition $block): list<string|ValidationRule> $rules
     */
    public function extraRules(\Closure $rules): void
    {
        $this->extraRules[] = $rules;
    }

    /**
     * The errors of the block values of a record of $def.
     *
     * @param array<string, mixed>       $values the record's values
     * @param array<string, list<mixed>> $given  the rules the caller gave per field ([] for a field: not checked)
     * @return array<string, list<string>>
     */
    public function errors(TypeDefinition $def, array $values, array $given = []): array
    {
        $errors = [];
        $hidden = array_flip(FieldVisibility::hiddenFieldsOf($def, $values));
        foreach ($def->fields as $name => $field) {
            if (isset($hidden[$name]) || ($given[$name] ?? null) === []) {
                continue;
            }
            foreach (BlockLists::of($field, $values[$name] ?? null, $this->sets) as $path => $list) {
                $errors += $this->blocks($list, $def->locales, $name . '.' . ($path === '' ? '' : $path . '.'));
            }
        }

        $type = $this->layouts?->forType($def->key);
        if ($type !== null && ($given[PageLayouts::AREAS] ?? null) !== []) {
            foreach ($this->layouts->resolveValues($def, $values)?->placement->placed() ?? [] as $area => $list) {
                $errors += $this->blocks($list, $def->locales, PageLayouts::AREAS . '.' . $area . '.');
            }
        }

        return $errors;
    }

    /**
     * The errors of one block list, keyed "<prefix><uid>.<leaf>".
     *
     * @param list<array<string, mixed>> $list
     * @return array<string, list<string>>
     */
    public function blocks(array $list, Locales $locales, string $prefix = ''): array
    {
        $errors = [];
        foreach (array_values(array_filter($list, 'is_array')) as $index => $block) {
            $definition = $this->blocks->get((string) ($block['_block'] ?? ''));
            if ($definition === null) {
                continue;
            }
            $uid = BlockLists::uidOf($block, $index);
            $hidden = array_flip($this->blocks->hiddenFieldsOf($definition->key, $block));
            // The whole block is the data (in the default language), so a rule may read another
            // field of it (a select's "options_from"); only the fields with rules are checked.
            $data = [];
            $rules = [];
            foreach ($definition->fields as $name => $field) {
                $nested = BlockLists::of($field, $block[$name] ?? null, $this->sets);
                if (BlockLists::holds($field)) {
                    $data[$name] = $block[$name] ?? null;
                    if (!isset($hidden[$name])) {
                        foreach ($nested as $path => $children) {
                            $errors += $this->blocks($children, $locales, $prefix . $uid . '.' . $name . '.' . ($path === '' ? '' : $path . '.'));
                        }
                    }
                } else {
                    $data[$name] = $field->translatable
                        ? LocaleValue::pick($block[$name] ?? null, $locales, $locales->default)
                        : LocaleValue::default($block[$name] ?? null, $locales);
                }
                if (isset($hidden[$name])) {
                    continue;
                }
                $fieldRules = $this->rulesOf($field, $definition);
                if ($fieldRules !== []) {
                    $rules[$name] = $fieldRules;
                }
            }
            if ($rules === []) {
                continue;
            }
            foreach ($this->validators->make($rules, $data)->validate()->errors() as $field => $messages) {
                $errors[$prefix . $uid . '.' . $field] ??= $messages;
            }
        }

        return $errors;
    }

    /**
     * A block field's rules: its "validate", a select's options, its type's checks, the adapters'
     * rules; a rule name once, a rule object once.
     *
     * @return list<string|ValidationRule>
     */
    private function rulesOf(TypeFieldDefinition $field, BlockDefinition $block): array
    {
        $lists = [$field->validate, SelectRules::of($field, $block->fields, $this->kinds, $this->labels !== null ? ($this->labels)() : null), $this->kinds->rulesOf($field)];
        foreach ($this->extraRules as $extra) {
            $lists[] = $extra($field, $block);
        }
        $rules = [];
        $seen = [];
        foreach ($lists as $list) {
            foreach ($list as $rule) {
                $id = is_object($rule) ? 'o' . spl_object_id($rule) : 's' . $rule;
                if (!isset($seen[$id])) {
                    $seen[$id] = true;
                    $rules[] = $rule;
                }
            }
        }

        return $rules;
    }
}
