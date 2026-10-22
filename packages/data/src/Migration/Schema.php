<?php

declare(strict_types=1);

namespace Preflow\Data\Migration;

final class Schema
{
    public function __construct(
        private readonly \PDO $pdo,
    ) {}

    public function create(string $table, callable $callback): void
    {
        $builder = new Table($table);
        $callback($builder);

        $this->pdo->exec($builder->toSql());

        // Create indexes
        foreach ($builder->getIndexes() as $column) {
            $indexName = "idx_{$table}_{$column}";
            $this->pdo->exec("CREATE INDEX \"{$indexName}\" ON \"{$table}\" (\"{$column}\")");
        }
    }

    public function drop(string $table): void
    {
        $this->pdo->exec("DROP TABLE IF EXISTS \"{$table}\"");
    }

    public function hasTable(string $table): bool
    {
        $this->requireSqlite();
        $stmt = $this->pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$table]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @return list<string> Column names in table order
     */
    public function columns(string $table): array
    {
        $this->requireSqlite();
        $rows = $this->pdo->query("PRAGMA table_info(\"{$table}\")")->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(static fn (array $row): string => (string) $row['name'], $rows);
    }

    /** Adds a nullable column to an existing table. */
    public function addColumn(string $table, string $column, string $type = 'TEXT'): void
    {
        $this->requireSqlite();
        $this->pdo->exec("ALTER TABLE \"{$table}\" ADD COLUMN \"{$column}\" {$type}");
    }

    private function requireSqlite(): void
    {
        if ($this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new \LogicException('Schema introspection supports SQLite only for now.');
        }
    }
}
