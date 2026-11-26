<?php

declare(strict_types=1);

namespace Preflow\Fields\Rules;

use Preflow\Fields\TableOptions;
use Preflow\Validation\Message;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

/**
 * A table field's value (Table's stored object, or JSON text of it) is a table: "rows" a list of
 * rows, each a list of cells, "head" null or a list of cells, and each cell a text, a text per
 * language ({"en": "…"}) or null. It keeps to the field's "max_columns" (the widest row or the
 * header) and "max_rows" (the header not counted); a refusal names the count and the limit. An
 * empty value passes ("required" decides).
 *
 * Registered for every "table" field (FieldKinds::rules()).
 *
 * @since 0.91.0
 */
final class ValidTable implements ValidationRule
{
    public function __construct(
        private readonly TableOptions $options = new TableOptions(),
    ) {}

    public function validate(mixed $value, ValidationContext $context): true|Message
    {
        if ($value === null || $value === '' || $value === []) {
            return true;
        }
        $data = is_string($value) ? json_decode(trim($value), true) : $value;
        if (!is_array($data) || array_is_list($data) || !is_array($data['rows'] ?? null) || !array_is_list($data['rows'])) {
            return new Message('validation.table');
        }
        $head = $data['head'] ?? null;
        if ($head !== null && (!is_array($head) || !array_is_list($head) || !self::cells($head))) {
            return new Message('validation.table');
        }
        $width = count($head ?? []);
        foreach ($data['rows'] as $row) {
            if (!is_array($row) || !array_is_list($row) || !self::cells($row)) {
                return new Message('validation.table');
            }
            $width = max($width, count($row));
        }
        if ($this->options->maxColumns !== null && $width > $this->options->maxColumns) {
            return new Message('validation.table_columns', ['count' => (string) $width, 'max' => (string) $this->options->maxColumns]);
        }
        $rows = count($data['rows']);
        if ($this->options->maxRows !== null && $rows > $this->options->maxRows) {
            return new Message('validation.table_rows', ['count' => (string) $rows, 'max' => (string) $this->options->maxRows]);
        }

        return true;
    }

    /** @param list<mixed> $cells */
    private static function cells(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell === null || is_string($cell)) {
                continue;
            }
            if (!is_array($cell) || ($cell !== [] && array_is_list($cell))) {
                return false;
            }
            foreach ($cell as $locale => $text) {
                if (!is_string($locale) || !is_string($text)) {
                    return false;
                }
            }
        }

        return true;
    }
}
