<?php

declare(strict_types=1);

namespace Preflow\Testing\Fast;

/**
 * A MySQL database in set-up snapshots: SHOW CREATE TABLE and CHECKSUM TABLE make its state; a
 * restore keeps tables whose definition is unchanged (emptied and refilled, much faster than DROP
 * and CREATE) and recreates the rest.
 *
 * Refuses a connection whose current database isn't the one it was given, so a misconfigured test
 * never empties another database.
 */
final class MysqlSnapshotDatabase implements SnapshotDatabase
{
    /** Rows per INSERT. */
    private const int CHUNK = 200;

    /** @param \Closure(): \PDO $pdo */
    public function __construct(
        private readonly \Closure $pdo,
        private readonly string $database,
    ) {
    }

    /** @return array<string, array{create: string, checksum: string}> */
    public function state(): array
    {
        $pdo = $this->connection();
        $tables = $this->tables($pdo);
        if ($tables === []) {
            return [];
        }
        $checksums = $pdo->query('CHECKSUM TABLE ' . implode(', ', array_map(self::quoteName(...), $tables)))->fetchAll(\PDO::FETCH_NUM);
        $states = [];
        foreach ($checksums as $i => [, $checksum]) {
            $states[$tables[$i]] = ['create' => self::createStatement($pdo, $tables[$i]), 'checksum' => (string) $checksum];
        }

        return $states;
    }

    /** @return array<string, array{create: string, rows: list<array<string, mixed>>}> */
    public function capture(): array
    {
        $pdo = $this->connection();
        $tables = [];
        foreach ($this->tables($pdo) as $table) {
            $tables[$table] = [
                'create' => self::createStatement($pdo, $table),
                'rows' => $pdo->query('SELECT * FROM ' . self::quoteName($table))->fetchAll(\PDO::FETCH_ASSOC),
            ];
        }

        return $tables;
    }

    /** @param array<string, array{create: string, rows: list<array<string, mixed>>}> $tables */
    public function restore(array $tables): void
    {
        $pdo = $this->connection();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $current = [];
            foreach ($this->tables($pdo) as $table) {
                $current[$table] = self::createStatement($pdo, $table);
            }
            foreach (array_diff_key($current, $tables) as $table => $_) {
                $pdo->exec('DROP TABLE ' . self::quoteName((string) $table));
            }
            $empty = [];
            $counters = [];
            foreach ($tables as $table => ['create' => $create]) {
                $name = self::quoteName((string) $table);
                if (isset($current[$table]) && self::withoutAutoIncrement($current[$table]) === self::withoutAutoIncrement($create)) {
                    $empty[] = $name;
                    if (self::autoIncrement($current[$table]) !== self::autoIncrement($create)) {
                        $counters[$name] = self::autoIncrement($create) ?? 1;
                    }
                } else {
                    if (isset($current[$table])) {
                        $pdo->exec("DROP TABLE {$name}");
                    }
                    $pdo->exec($create);
                }
            }
            // One transaction: one write to disk instead of one for each table.
            $pdo->beginTransaction();
            try {
                foreach ($empty as $name) {
                    $pdo->exec("DELETE FROM {$name}");
                }
                foreach ($tables as $table => ['rows' => $rows]) {
                    $name = self::quoteName((string) $table);
                    foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                        $columns = implode(', ', array_map(self::quoteName(...), array_keys($chunk[0])));
                        $row = '(' . implode(', ', array_fill(0, count($chunk[0]), '?')) . ')';
                        $pdo->prepare("INSERT INTO {$name} ({$columns}) VALUES " . implode(', ', array_fill(0, count($chunk), $row)))
                            ->execute(array_merge(...array_map(array_values(...), $chunk)));
                    }
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();

                throw $e;
            }
            // Ids a test used are not handed out again by DELETE alone.
            foreach ($counters as $name => $next) {
                $pdo->exec("ALTER TABLE {$name} AUTO_INCREMENT = {$next}");
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    public function empty(): void
    {
        $pdo = $this->connection();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($this->tables($pdo) as $table) {
                $pdo->exec('DROP TABLE ' . self::quoteName($table));
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private function connection(): \PDO
    {
        $pdo = ($this->pdo)();
        $current = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($current !== $this->database) {
            throw new \LogicException(sprintf(
                'Snapshots may only change the database "%s", but the connection uses "%s".',
                $this->database,
                is_string($current) ? $current : '(none)',
            ));
        }

        return $pdo;
    }

    /** @return list<string> */
    private function tables(\PDO $pdo): array
    {
        return array_map(strval(...), $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN));
    }

    private static function createStatement(\PDO $pdo, string $table): string
    {
        return (string) $pdo->query('SHOW CREATE TABLE ' . self::quoteName($table))->fetchColumn(1);
    }

    private static function withoutAutoIncrement(string $create): string
    {
        return (string) preg_replace('~ AUTO_INCREMENT=\d+~', '', $create);
    }

    private static function autoIncrement(string $create): ?int
    {
        return preg_match('~ AUTO_INCREMENT=(\d+)~', $create, $m) === 1 ? (int) $m[1] : null;
    }

    private static function quoteName(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
