<?php

declare(strict_types=1);

namespace Preflow\Data\Schema;

use Preflow\Data\DataManager;
use Preflow\Data\FieldKinds;
use Preflow\Data\Migration\MigrationColumns;
use Preflow\Data\ModelMetadata;
use Preflow\Data\ModelSchema;
use Preflow\Data\PdoConnection;

/**
 * Plain model classes say which text fields are exact twice: #[Field(exact: true)] (what JSON
 * storage, loose search and Validation\Unique follow) and their migration's ->exact() (the SQL
 * column's collation, which = and the unique index follow). `preflow migrate` (the step
 * `data.model-exactness`) lists each field where the two disagree, on the storage the migrations
 * ran on, for the tables a migration made since 0.78 (MigrationColumns); a table without recorded
 * intent is listed by TextRuleConversion instead. Warnings only: nothing is changed.
 *
 * @internal
 * @since 0.78
 */
final class ModelExactness
{
    /** @param list<class-string> $classes plain model classes (ModelClasses::plain()) */
    public function __construct(
        private readonly DataManager $dm,
        private readonly array $classes,
        private readonly ?string $migrated,
    ) {}

    /** @return list<string> one warning per field whose two declarations disagree */
    public function check(): array
    {
        if ($this->migrated === null) {
            return [];
        }
        $driver = $this->dm->driver($this->migrated);
        if (!$driver instanceof PdoConnection) {
            return [];
        }
        $intents = (new MigrationColumns($driver->pdo()))->tables();
        $kinds = $this->dm->fieldKinds();
        $lines = [];
        foreach ($this->classes as $class) {
            $meta = ModelMetadata::for($class);
            if ($this->dm->driver($meta->storage) !== $driver || !isset($intents[$meta->table])) {
                continue;
            }
            foreach ($meta->fields as $name => $field) {
                $name = (string) $name;
                $type = ModelSchema::fieldType($meta, $name);
                $intent = $intents[$meta->table][$name] ?? null;
                if ($type === null || $intent === null || $name === $meta->idField || !$kinds->isText($type) || $kinds->isList($type)) {
                    continue;
                }
                if ($field->exact && $intent === MigrationColumns::TEXT) {
                    $lines[] = "warning: {$class}::\${$name} is #[Field(exact: true)], but its migration made {$meta->table}.{$name} a text column (no ->exact()): SQL compares it ignoring case, JSON exactly. Convert the column (Schema::convertColumn() with ->exact()) or drop exact.";
                } elseif (!$field->exact && $intent === MigrationColumns::EXACT) {
                    $lines[] = "warning: {$class}::\${$name} is a text field, but its migration made {$meta->table}.{$name} exact (->exact()): SQL compares it exactly, JSON ignoring case. Mark it #[Field(exact: true)] or convert the column (Schema::convertColumn()).";
                }
            }
        }

        return $lines;
    }
}
