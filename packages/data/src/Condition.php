<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * A check on one stored record that a conditional write depends on (DataManager::updateWhen(),
 * deleteWhen()): the same value on every storage. A SQL storage compiles it into the write's
 * WHERE (Driver\ConditionCompiler), any other storage checks it with holds() on the record it
 * read under its lock.
 *
 *     Condition::all(
 *         Condition::where('purpose', '=', 'reset'),
 *         Condition::where('expires_at', '>', $now),
 *     )
 *
 * What a comparison compares:
 * - where() with a whole number (or a list of them for IN) compares the field as a whole number:
 *   the whole part of a number or of text that is one ('3', '3.0' and 3.9 are 3), 0 for text with
 *   no number in it, as Driver\Dialect::wholeNumber() reads it. A field without a value (null, ''
 *   or no key) matches no number comparison, `!=` included.
 * - where() with a text (or a list of texts) compares the field's text exactly, character by
 *   character (byte order for < and >): 'ab' is not 'Ab', 'Ab ' is not 'Ab' (also where a
 *   collation pads with spaces; a control character before trailing spaces is up to each storage
 *   there). A field holding null or no key
 *   matches no text comparison, `!=` included; '' is a text. Compare a number or boolean field
 *   with a whole number: compared with a text, it is up to each storage (SQLite compares it as a
 *   number).
 * - whereField() compares two fields of the same record, as whole numbers (numbers: true) or as
 *   texts; a side without a value matches nothing.
 * - isEmpty() holds for null, '' and a missing key (not for 0, '0', spaces or an empty list).
 * - all() holds when each of its conditions holds (and with none), any() when one does (never
 *   with none).
 *
 * There is no "not": write the opposite comparison, or isEmpty() for "no value". A float, a
 * boolean or null as a value is refused (InvalidArgumentException): give a whole number, a text,
 * or use isEmpty().
 *
 * The properties describe the condition for a driver that compiles it itself; kind says which
 * ones are set.
 *
 * @since 0.89.0
 */
final class Condition
{
    /** where(): $field $operator $value. */
    public const string VALUE = 'value';

    /** whereField(): $field $operator $other. */
    public const string FIELD = 'field';

    /** isEmpty(): $field holds null, '' or nothing. */
    public const string EMPTY = 'empty';

    /** all(): every one of $conditions holds. */
    public const string ALL = 'all';

    /** any(): one of $conditions holds. */
    public const string ANY = 'any';

    /** The comparisons where() and whereField() take (IN only with where()). */
    public const array OPERATORS = ['=', '!=', '<', '>', '<=', '>=', 'IN'];

    /**
     * @param self::VALUE|self::FIELD|self::EMPTY|self::ALL|self::ANY $kind
     * @param int|string|list<int>|list<string>|null $value where()'s value (a list for IN)
     * @param list<self> $conditions all()'s or any()'s conditions
     */
    private function __construct(
        public readonly string $kind,
        public readonly string $field = '',
        public readonly string $operator = '',
        public readonly int|string|array|null $value = null,
        public readonly string $other = '',
        public readonly bool $numbers = false,
        public readonly array $conditions = [],
    ) {}

    /**
     * The field compared with a value: a whole number or a text, or for IN a list of either kind
     * (an empty list matches nothing). The value's kind decides how the field is read (see above).
     *
     * @param mixed $value a whole number or a text; for IN a list of whole numbers or of texts
     * @throws \InvalidArgumentException for an unknown operator or a value of another kind
     */
    public static function where(string $field, string $operator, mixed $value): self
    {
        self::checkField($field);
        self::checkOperator($operator);
        if ($operator === 'IN') {
            if (!is_array($value) || !array_is_list($value)) {
                throw new \InvalidArgumentException("Condition::where('{$field}', 'IN', …) takes a list of values; give a list of whole numbers or of texts.");
            }
            $ints = array_filter($value, is_int(...));
            $texts = array_filter($value, is_string(...));
            if (count($ints) !== count($value) && count($texts) !== count($value)) {
                throw new \InvalidArgumentException("Condition::where('{$field}', 'IN', …) takes whole numbers or texts, all of one kind; give a list of one kind.");
            }

            return new self(self::VALUE, $field, 'IN', $value, numbers: $value !== [] && $ints !== []);
        }
        if (is_array($value)) {
            throw new \InvalidArgumentException("Condition::where('{$field}', '{$operator}', …) takes one value, a list only with 'IN'; give a whole number or a text.");
        }
        if (!is_int($value) && !is_string($value)) {
            $hint = $value === null ? "for no value use Condition::isEmpty('{$field}')" : 'give a whole number or a text';
            throw new \InvalidArgumentException("Condition::where('{$field}', '{$operator}', …) cannot compare with " . get_debug_type($value) . "; {$hint}.");
        }

        return new self(self::VALUE, $field, $operator, $value, numbers: is_int($value));
    }

    /**
     * The field compared with another field of the same record: as whole numbers when $numbers is
     * true, else as texts (see above). IN is not one of its operators.
     *
     * @throws \InvalidArgumentException for an unknown operator
     */
    public static function whereField(string $field, string $operator, string $other, bool $numbers = false): self
    {
        self::checkField($field);
        self::checkField($other);
        self::checkOperator($operator);
        if ($operator === 'IN') {
            throw new \InvalidArgumentException("Condition::whereField('{$field}', 'IN', '{$other}') cannot compare with IN; use =, !=, <, >, <= or >=.");
        }

        return new self(self::FIELD, $field, $operator, other: $other, numbers: $numbers);
    }

    /** The field holds no value: null, '' or no key at all. */
    public static function isEmpty(string $field): self
    {
        self::checkField($field);

        return new self(self::EMPTY, $field);
    }

    /** Every condition holds (true with none). */
    public static function all(self ...$conditions): self
    {
        return new self(self::ALL, conditions: array_values($conditions));
    }

    /** At least one condition holds (false with none). */
    public static function any(self ...$conditions): self
    {
        return new self(self::ANY, conditions: array_values($conditions));
    }

    /**
     * Whether the condition holds on a stored record (the row as the driver's findOne() returns
     * it): the reference for every storage, and what DataManager checks on a storage that does
     * not compile it.
     *
     * @param array<string, mixed> $row
     */
    public function holds(array $row): bool
    {
        switch ($this->kind) {
            case self::ALL:
                foreach ($this->conditions as $condition) {
                    if (!$condition->holds($row)) {
                        return false;
                    }
                }

                return true;
            case self::ANY:
                foreach ($this->conditions as $condition) {
                    if ($condition->holds($row)) {
                        return true;
                    }
                }

                return false;
            case self::EMPTY:
                $stored = $row[$this->field] ?? null;

                return $stored === null || $stored === '';
            case self::FIELD:
                $read = $this->numbers ? self::wholeNumber(...) : self::text(...);
                $left = $read($row[$this->field] ?? null);
                $right = $read($row[$this->other] ?? null);

                return $left !== null && $right !== null && self::compare($left, $this->operator, $right);
            default:
                $stored = $this->numbers ? self::wholeNumber($row[$this->field] ?? null) : self::text($row[$this->field] ?? null);
                if ($stored === null) {
                    return false;
                }
                $value = $this->value;

                return is_array($value) ? in_array($stored, $value, true) : $value !== null && self::compare($stored, $this->operator, $value);
        }
    }

    /**
     * A stored value as a whole number, as Driver\Dialect::wholeNumber() reads it: null for null
     * and '' (no number), the whole part, towards zero, of a number or of text that is one
     * ('3.0' is 3, '-2.5' is -2), else what PHP reads at its start (0 for text with no number in
     * it; text that only starts with a number is up to each storage).
     */
    public static function wholeNumber(mixed $stored): ?int
    {
        return match (true) {
            $stored === null, $stored === '' => null,
            is_int($stored) => $stored,
            is_float($stored), is_bool($stored) => (int) $stored,
            is_string($stored) && preg_match('/^-?\d+$/', $stored) === 1 => (int) $stored,
            is_string($stored) && is_numeric($stored) => (int) (float) $stored,
            is_string($stored) => (int) $stored,
            default => 0,
        };
    }

    /**
     * A stored value as the text a text comparison reads: null for null (no value), a boolean as
     * '1' or '0', a list or object as its JSON text (as a SQL storage holds it), else the value as
     * text.
     */
    public static function text(mixed $stored): ?string
    {
        return match (true) {
            $stored === null => null,
            is_bool($stored) => $stored ? '1' : '0',
            is_array($stored) => json_encode($stored, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            is_scalar($stored), $stored instanceof \Stringable => (string) $stored,
            default => null,
        };
    }

    private static function compare(int|string $left, string $operator, int|string $right): bool
    {
        $order = is_int($left) && is_int($right) ? $left <=> $right : strcmp((string) $left, (string) $right);

        return match ($operator) {
            '=' => $order === 0,
            '!=' => $order !== 0,
            '<' => $order < 0,
            '>' => $order > 0,
            '<=' => $order <= 0,
            default => $order >= 0,
        };
    }

    private static function checkOperator(string $operator): void
    {
        if (!in_array($operator, self::OPERATORS, true)) {
            throw new \InvalidArgumentException("A condition cannot compare with '{$operator}'; use one of " . implode(', ', self::OPERATORS) . '.');
        }
    }

    private static function checkField(string $field): void
    {
        if ($field === '') {
            throw new \InvalidArgumentException('A condition needs a field name; give the name of a stored field.');
        }
    }
}
