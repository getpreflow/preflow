<?php

declare(strict_types=1);

namespace Preflow\Fields;

/**
 * A table field's options, top-level in its config:
 *
 *     "prices": {"type": "table", "max_columns": 4, "max_rows": 20}
 *
 * - "max_columns": at most this many columns (the header row's cells count too);
 * - "max_rows": at most this many rows, the header row not counted.
 *
 * Without them a table has no limit. The check (ValidTable) applies with and without Folio.
 *
 * @since 0.91.0
 */
final readonly class TableOptions
{
    public function __construct(
        public ?int $maxColumns = null,
        public ?int $maxRows = null,
    ) {}

    /** @param array<string, mixed> $config the field's config */
    public static function fromConfig(array $config): self
    {
        $limit = static fn (mixed $value): ?int => is_int($value) && $value > 0 ? $value : null;

        return new self($limit($config['max_columns'] ?? null), $limit($config['max_rows'] ?? null));
    }

    /**
     * @param array<string, mixed> $config the field's config (its "translatable" too)
     * @throws \LogicException
     */
    public static function check(array $config): void
    {
        if (($config['translatable'] ?? false) === true) {
            throw new \LogicException('A table field cannot be translatable; its cells are translated in the table itself (a text per language). Remove "translatable".');
        }
        FieldOptions::refuseNested('table', $config, ['max_columns', 'max_rows']);
        foreach (['max_columns', 'max_rows'] as $key) {
            if (array_key_exists($key, $config) && (!is_int($config[$key]) || $config[$key] < 1)) {
                throw new \LogicException("\"{$key}\" must be a whole number of 1 or more, such as 20; leave it out for no limit.");
            }
        }
    }
}
