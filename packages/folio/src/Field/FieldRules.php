<?php

declare(strict_types=1);

namespace Preflow\Folio\Field;

use Preflow\Validation\ValidationRule;

/**
 * Merges a field's rule lists (its `validate` and its type's rules()) without casting a rule
 * object that isn't Stringable: a rule name is kept once, an object once per instance, and a
 * Stringable object not at all when its string is already among the names (`required` and a
 * Required object would show the same error twice).
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
                    if (isset($objects[$id]) || ($rule instanceof \Stringable && isset($names[(string) $rule]))) {
                        continue;
                    }
                    $objects[$id] = true;
                    if ($rule instanceof \Stringable) {
                        $names[(string) $rule] = true;
                    }
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
