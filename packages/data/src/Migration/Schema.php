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
}
