<?php

declare(strict_types=1);

namespace Preflow\Folio\Field;

use Preflow\Validation\ValidationRule;

/**
 * Merges a field's rule lists (its `validate` and its type's rules()) without casting: a rule
 * name is kept once, a ValidationRule object once per instance, whether or not it is Stringable.
 *
 * @since 0.66
 */
final class FieldRules
{
    /**
     * @param list<string|ValidationRule> ...$lists
     * @return list<string|ValidationRule> in the order first seen
     */
    public static function merge(array ...$lists): array
    {
        $merged = [];
        $names = [];
        $objects = [];
        foreach ($lists as $list) {
            foreach ($list as $rule) {
                if (is_object($rule)) {
                    $id = spl_object_id($rule);
                    if (isset($objects[$id])) {
                        continue;
                    }
                    $objects[$id] = true;
                } else {
                    $rule = (string) $rule;
                    if (isset($names[$rule])) {
                        continue;
                    }
                    $names[$rule] = true;
                }
                $merged[] = $rule;
            }
        }

        return $merged;
    }
}
