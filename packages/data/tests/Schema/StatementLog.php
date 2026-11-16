<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Schema;

/** A PDO that records every statement it runs (exec(), query(), prepare()), for tests that count them. */
final class StatementLog extends \PDO
{
    /** @var list<string> */
    public array $statements = [];

    public function exec(string $statement): int|false
    {
        $this->statements[] = $statement;

        return parent::exec($statement);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        $this->statements[] = $query;

        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        $this->statements[] = $query;

        return parent::prepare($query, $options);
    }

    /** @return list<string> the statements since the last clear() that match the pattern */
    public function matching(string $pattern): array
    {
        return array_values(array_filter($this->statements, static fn (string $sql): bool => preg_match($pattern, $sql) === 1));
    }

    public function clear(): void
    {
        $this->statements = [];
    }
}
