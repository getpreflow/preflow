<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * A field's visibility rule (show_when in its config): the field shows only when the rule on
 * other fields of the same record (or block) holds. Folio's editor mirrors holds() in the
 * browser; the server decides the same way (DataManager drops a hidden field's rules before
 * validating). The cases both sides must agree on are in tests/Fixtures/show-when-cases.json.
 *
 *     {"field": "kind", "=": "video"}          {"field": "kind", "!=": "video"}
 *     {"field": "kind", "in": ["link", "url"]}  {"field": "kind", "not_in": ["link"]}
 *     {"field": "note", "empty": true}          {"field": "note", "filled": true}
 *     {"all": [rule, …]}                        {"any": [rule, …]}
 *
 * What a rule compares (built on Condition's reading of stored values):
 * - A field without a value is empty: no key, null, '' or an empty list. "0", 0, false and
 *   spaces are values. "empty" holds for an empty field, "filled" for any other.
 * - "=" with true or false reads the field as on or off: true, 1, '1', 'true' and 'on' are on,
 *   anything else (an empty field too) is off.
 * - "=" with another value: a number field (type "number") compares as whole numbers
 *   (Condition::wholeNumber(): "3.0" and 3.9 are 3), every other field as text, exactly
 *   (Condition::text(): "Video" is not "video"). An empty field matches no value.
 * - "!=" holds exactly when "=" does not (an empty field is not "video"); "in" holds when one
 *   value of the list matches as "=" does, "not_in" when none does.
 * - "all" holds when each of its rules holds, "any" when one does; each needs one rule or more.
 *
 * @since 0.91.0
 */
final class FieldVisibility
{
    /** The comparisons a rule takes next to "field". */
    public const array OPERATORS = ['=', '!=', 'in', 'not_in', 'empty', 'filled'];

    private const string NOT_AN_OBJECT = 'show_when is an object such as {"field": "kind", "=": "video"}; give one rule like it, or combine rules with {"all": [...]} or {"any": [...]}.';

    /** @var \WeakMap<TypeDefinition, array<string, self>>|null each type's parsed rules (hiddenFieldsOf()) */
    private static ?\WeakMap $parsedByType = null;

    /**
     * @param 'all'|'any'|'='|'!='|'in'|'not_in'|'empty'|'filled' $operator
     * @param list<bool|int|float|string> $values the values compared with (one for = and !=)
     * @param list<self> $rules all's or any's rules
     */
    private function __construct(
        private readonly string $operator,
        private readonly string $field = '',
        private readonly array $values = [],
        private readonly array $rules = [],
        private readonly bool $number = false,
    ) {}

    /**
     * The rule a field's show_when holds.
     *
     * @param array<mixed> $showWhen the show_when array, as the model file writes it
     * @param array<string, string> $types field name => field type, for the fields the rule names
     *                                     (a field of type "number" compares as whole numbers)
     * @throws ShowWhenException for a malformed rule, saying what to write instead
     */
    public static function fromConfig(array $showWhen, array $types = []): self
    {
        if (array_is_list($showWhen)) {
            throw new ShowWhenException(self::NOT_AN_OBJECT);
        }
        $shown = self::show($showWhen);
        foreach (['all', 'any'] as $join) {
            if (!array_key_exists($join, $showWhen)) {
                continue;
            }
            $list = $showWhen[$join];
            if (count($showWhen) > 1) {
                throw new ShowWhenException("show_when {$shown} combines rules with \"{$join}\" and holds nothing else; move the other keys into a rule of the list.");
            }
            if (!is_array($list) || !array_is_list($list) || $list === []) {
                throw new ShowWhenException("show_when {$shown} needs a list of one or more rules for \"{$join}\".");
            }
            $rules = [];
            foreach ($list as $rule) {
                if (!is_array($rule)) {
                    throw new ShowWhenException("show_when {$shown} holds " . self::show($rule) . " in its \"{$join}\" list; each entry is a rule such as {\"field\": \"kind\", \"=\": \"video\"}.");
                }
                $rules[] = self::fromConfig($rule, $types);
            }

            return new self($join, rules: $rules);
        }

        $field = $showWhen['field'] ?? null;
        if (!is_string($field) || $field === '') {
            throw new ShowWhenException("show_when {$shown} names no field; give \"field\" with the name of another field.");
        }
        $operators = array_values(array_diff(array_map(strval(...), array_keys($showWhen)), ['field']));
        if ($operators === []) {
            throw new ShowWhenException("show_when {$shown} has no operator; give one of " . implode(', ', self::OPERATORS) . ' (or all/any with a list of rules).');
        }
        if (count($operators) > 1) {
            throw new ShowWhenException("show_when {$shown} has more than one operator; give one, or combine rules with all/any.");
        }
        $operator = $operators[0];
        if (!in_array($operator, self::OPERATORS, true)) {
            throw new ShowWhenException("show_when {$shown} has the operator \"{$operator}\", which show_when does not know; use one of " . implode(', ', self::OPERATORS) . '.');
        }
        $value = $showWhen[$operator];

        if ($operator === 'empty' || $operator === 'filled') {
            if ($value !== true) {
                $opposite = $operator === 'empty' ? 'filled' : 'empty';
                throw new ShowWhenException("show_when {$shown} takes \"{$operator}\": true only; for the opposite write {\"field\": \"{$field}\", \"{$opposite}\": true}.");
            }

            return new self($operator, $field);
        }

        $number = ($types[$field] ?? null) === 'number';
        if ($operator === 'in' || $operator === 'not_in') {
            if (!is_array($value) || !array_is_list($value) || $value === []) {
                throw new ShowWhenException("show_when {$shown} needs a list of values for \"{$operator}\", such as [\"link\", \"video\"].");
            }
            foreach ($value as $one) {
                self::checkValue($one, $shown, $field, $operator, $number);
            }

            return new self($operator, $field, $value, number: $number);
        }

        if (is_array($value)) {
            throw new ShowWhenException("show_when {$shown} compares with a list; use \"in\" for a list of values, or give one value.");
        }
        self::checkValue($value, $shown, $field, $operator, $number);

        return new self($operator, $field, [$value], number: $number);
    }

    /**
     * Whether the rule holds on a record's values (a missing key is an empty field).
     *
     * @param array<string, mixed> $values
     */
    public function holds(array $values): bool
    {
        switch ($this->operator) {
            case 'all':
                foreach ($this->rules as $rule) {
                    if (!$rule->holds($values)) {
                        return false;
                    }
                }

                return true;
            case 'any':
                foreach ($this->rules as $rule) {
                    if ($rule->holds($values)) {
                        return true;
                    }
                }

                return false;
        }
        $stored = $values[$this->field] ?? null;

        return match ($this->operator) {
            'empty' => self::isEmpty($stored),
            'filled' => !self::isEmpty($stored),
            '=', 'in' => $this->matches($stored),
            default => !$this->matches($stored), // '!=', 'not_in'
        };
    }

    /**
     * The fields the rule names, each once, in the order the rule names them.
     *
     * @return list<string>
     */
    public function sources(): array
    {
        if ($this->rules === []) {
            return [$this->field];
        }
        $sources = [];
        foreach ($this->rules as $rule) {
            $sources = [...$sources, ...$rule->sources()];
        }

        return array_values(array_unique($sources));
    }

    /**
     * The show_when rule of each field that has one, from type field definitions
     * (TypeFieldDefinition::$config['show_when']) or from field config arrays as a model file or
     * a block field writes them (['show_when' => …]).
     *
     * @param array<string, TypeFieldDefinition|array<string, mixed>> $fields
     * @return array<string, mixed> field name => its show_when, as written (an object for a rule
     *                              that can work; assertRules() refuses anything else)
     */
    public static function rulesOf(array $fields): array
    {
        $rules = [];
        foreach ($fields as $name => $field) {
            $rule = $field instanceof TypeFieldDefinition ? $field->config['show_when'] ?? null : $field['show_when'] ?? null;
            if ($rule !== null) {
                $rules[(string) $name] = $rule;
            }
        }

        return $rules;
    }

    /**
     * The fields whose rule does not hold on $values, in the order of $fields. A field whose rule
     * names a hidden field is hidden too (A hides B, so C, which names B, hides). A field without
     * show_when is never hidden. A rule naming a field $fields does not have reads it as empty.
     *
     * @param array<string, TypeFieldDefinition|array<string, mixed>> $fields
     * @param array<string, mixed> $values the record's (or block's) values
     * @return list<string>
     * @throws ShowWhenException for a malformed rule or a cycle (assertRules() refuses both at boot)
     */
    public static function hiddenFields(array $fields, array $values): array
    {
        return self::hide(self::parseAll($fields), $values);
    }

    /**
     * hiddenFields() for a type's fields, with the type's rules parsed once per definition (a
     * list reads many records of one type). Holds nothing but the parsed rules, which depend on
     * the definition alone; the memo goes with the definition.
     *
     * @param array<string, mixed> $values the record's values
     * @return list<string>
     */
    public static function hiddenFieldsOf(TypeDefinition $type, array $values): array
    {
        self::$parsedByType ??= new \WeakMap();
        $parsed = self::$parsedByType[$type] ??= self::parseAll($type->fields);

        return self::hide($parsed, $values);
    }

    /**
     * @param array<string, TypeFieldDefinition|array<string, mixed>> $fields
     * @return array<string, self>
     */
    private static function parseAll(array $fields): array
    {
        $rules = self::rulesOf($fields);
        if ($rules === []) {
            return [];
        }
        $types = self::typesOf($fields);
        $parsed = [];
        foreach ($rules as $name => $rule) {
            $parsed[$name] = self::parse($rule, $types);
        }

        return $parsed;
    }

    /**
     * @param mixed $rule a field's show_when as written
     * @param array<string, string> $types
     */
    private static function parse(mixed $rule, array $types): self
    {
        if (!is_array($rule)) {
            throw new ShowWhenException(self::NOT_AN_OBJECT);
        }

        return self::fromConfig($rule, $types);
    }

    /**
     * @param array<string, self> $parsed
     * @param array<string, mixed> $values
     * @return list<string>
     */
    private static function hide(array $parsed, array $values): array
    {
        if ($parsed === []) {
            return [];
        }
        $hidden = [];
        foreach (array_keys($parsed) as $name) {
            self::resolve($name, $parsed, $values, $hidden, []);
        }

        // In the order of the fields (resolve() decides a field's sources first).
        return array_values(array_filter(array_keys($parsed), static fn (string $name): bool => $hidden[$name] ?? false));
    }

    /**
     * Refuses a set of fields whose show_when rules cannot work: a malformed rule, or one naming
     * a field the owner does not have, the field itself, a translatable field, or a field that
     * depends on it again (a cycle). Run when a type registry or block registry is built.
     *
     * @param string $owner how messages name the owner, such as 'Type "page"' or 'Block "hero"'
     * @param array<string, TypeFieldDefinition|array<string, mixed>> $fields every field of the owner
     * @param \Closure(string): bool $isTranslatable whether a field of the owner is translatable
     * @param string $of what the owner is, for the messages ("type" or "block")
     * @throws ShowWhenException naming the owner and the field
     */
    public static function assertRules(string $owner, array $fields, \Closure $isTranslatable, string $of = 'type'): void
    {
        $rules = self::rulesOf($fields);
        if ($rules === []) {
            return;
        }
        $types = self::typesOf($fields);
        $sources = [];
        foreach ($rules as $name => $rule) {
            $prefix = "{$owner}: field \"{$name}\": ";
            try {
                $parsed = self::parse($rule, $types);
            } catch (ShowWhenException $e) {
                throw new ShowWhenException($prefix . $e->getMessage(), 0, $e);
            }
            foreach ($parsed->sources() as $source) {
                if ($source === $name) {
                    throw new ShowWhenException("{$prefix}show_when names the field itself. Name another field of the same {$of}.");
                }
                if (!array_key_exists($source, $fields)) {
                    throw new ShowWhenException("{$prefix}show_when names \"{$source}\", which is not a field of the {$of}. Name a field of the same {$of}.");
                }
                if ($isTranslatable($source)) {
                    throw new ShowWhenException("{$prefix}show_when names \"{$source}\", which is translatable. Name a field that is not translatable (one value in every language).");
                }
            }
            $sources[$name] = $parsed->sources();
        }
        foreach ($sources as $name => $named) {
            foreach ($named as $source) {
                $path = self::pathBack($source, $name, $sources, [$name]);
                if ($path !== null) {
                    $cycle = implode(' → ', [...$path, $name]);
                    throw new ShowWhenException("{$owner}: field \"{$name}\": show_when names \"{$source}\", which depends on \"{$name}\" again ({$cycle}). Break the cycle: let one of these fields show without depending on the others.");
                }
            }
        }
    }

    /**
     * The path from $field through the rules to $target ($seen first), or null when none leads there.
     *
     * @param array<string, list<string>> $sources
     * @param list<string> $seen
     * @return list<string>|null
     */
    private static function pathBack(string $field, string $target, array $sources, array $seen): ?array
    {
        $seen[] = $field;
        foreach ($sources[$field] ?? [] as $next) {
            if ($next === $target) {
                return $seen;
            }
            if (!in_array($next, $seen, true)) {
                $path = self::pathBack($next, $target, $sources, $seen);
                if ($path !== null) {
                    return $path;
                }
            }
        }

        return null;
    }

    /**
     * Whether $name is hidden, deciding its sources first.
     *
     * @param array<string, self> $parsed
     * @param array<string, mixed> $values
     * @param array<string, bool> $hidden decided so far
     * @param array<string, true> $deciding the fields being decided (a cycle guard)
     */
    private static function resolve(string $name, array $parsed, array $values, array &$hidden, array $deciding): bool
    {
        if (isset($hidden[$name])) {
            return $hidden[$name];
        }
        if (!isset($parsed[$name])) {
            return false;
        }
        if (isset($deciding[$name])) {
            throw new ShowWhenException("Field \"{$name}\": show_when depends on itself through other fields (a cycle). Let one of these fields show without depending on the others.");
        }
        $deciding[$name] = true;
        foreach ($parsed[$name]->sources() as $source) {
            if (self::resolve($source, $parsed, $values, $hidden, $deciding)) {
                return $hidden[$name] = true;
            }
        }

        return $hidden[$name] = !$parsed[$name]->holds($values);
    }

    /**
     * @param array<string, TypeFieldDefinition|array<string, mixed>> $fields
     * @return array<string, string>
     */
    private static function typesOf(array $fields): array
    {
        $types = [];
        foreach ($fields as $name => $field) {
            $type = $field instanceof TypeFieldDefinition ? $field->type : $field['type'] ?? 'string';
            $types[(string) $name] = is_string($type) ? $type : 'string';
        }

        return $types;
    }

    private function matches(mixed $stored): bool
    {
        foreach ($this->values as $value) {
            if (is_bool($value)) {
                if (self::isOn($stored) === $value) {
                    return true;
                }
                continue;
            }
            if (self::isEmpty($stored)) {
                continue;
            }
            if ($this->number) {
                if (Condition::wholeNumber($stored) === Condition::wholeNumber($value)) {
                    return true;
                }
            } elseif (Condition::text($stored) === Condition::text($value)) {
                return true;
            }
        }

        return false;
    }

    private static function isEmpty(mixed $stored): bool
    {
        return $stored === null || $stored === '' || $stored === [];
    }

    private static function isOn(mixed $stored): bool
    {
        return in_array($stored, [true, 1, '1', 'true', 'on'], true);
    }

    private static function checkValue(mixed $value, string $shown, string $field, string $operator, bool $number): void
    {
        if ($value === null) {
            throw new ShowWhenException("show_when {$shown} compares with no value; for no value use {\"field\": \"{$field}\", \"empty\": true}.");
        }
        if ($value === '') {
            $hint = $operator === '!=' || $operator === 'not_in'
                ? "for a value use {\"field\": \"{$field}\", \"filled\": true}"
                : "for no value use {\"field\": \"{$field}\", \"empty\": true}";
            throw new ShowWhenException("show_when {$shown} compares with no value; {$hint}.");
        }
        if (!is_scalar($value)) {
            throw new ShowWhenException("show_when {$shown} compares with " . self::show($value) . '; give a text, a number, true or false.');
        }
        // A number field compares as whole numbers: any other value would read as 0 and match the wrong records.
        $whole = is_int($value) || is_bool($value)
            || (is_float($value) && is_finite($value) && floor($value) === $value)
            || (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1);
        if ($number && !$whole) {
            throw new ShowWhenException("show_when {$shown} compares the number field \"{$field}\" with " . self::show($value) . '; give a whole number, such as 3.');
        }
    }

    private static function show(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
