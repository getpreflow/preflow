<?php

declare(strict_types=1);

namespace Preflow\Data\Driver;

final class MysqlDriver extends PdoDriver
{
    /** Strict, portable session mode; ANSI_QUOTES is deliberately absent (we quote with backticks). */
    public const SQL_MODE = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

    /**
     * Options to build the PDO with. FOUND_ROWS (UPDATE reports matched, not
     * changed, rows) can only be set at connect time, so it lives here and not
     * in the constructor; sql_mode and charset are set by the constructor.
     *
     * @return array<int, mixed>
     */
    public static function connectionOptions(): array
    {
        return [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \Pdo\Mysql::ATTR_FOUND_ROWS => true,
        ];
    }

    public function __construct(\PDO $pdo, ?\Preflow\Core\Debug\DebugCollector $collector = null)
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec("SET NAMES 'utf8mb4'");
        $pdo->exec("SET SESSION sql_mode = '" . self::SQL_MODE . "'");

        $dialect = new MysqlDialect();
        parent::__construct($pdo, $dialect, new QueryCompiler($dialect), $collector);
    }

    /** Seconds exclusive() waits for the type's lock before it gives up. */
    public const int LOCK_WAIT = 10;

    /**
     * Holding the named lock preflow_unique_{type} (GET_LOCK, per connection, nestable). Saves
     * outside a transaction commit before the lock is released; in a caller's transaction the
     * lock is released before the caller commits.
     */
    public function exclusive(string $type, \Closure $fn): mixed
    {
        $name = 'preflow_unique_' . $type;
        if (strlen($name) > 64) {
            $name = substr($name, 0, 55) . '_' . sprintf('%08x', crc32($name)); // GET_LOCK takes 64 characters
        }
        $lock = $this->pdo->prepare('SELECT GET_LOCK(?, ' . self::LOCK_WAIT . ')');
        $lock->execute([$name]);
        $taken = (int) $lock->fetchColumn() === 1;
        $lock->closeCursor();
        if (!$taken) {
            throw new \RuntimeException("Could not take the lock {$name} within " . self::LOCK_WAIT . ' seconds.');
        }
        try {
            return $fn();
        } finally {
            $release = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$name]);
            $release->closeCursor();
        }
    }

    /** PDO sends false as '', which strict mode refuses for a TINYINT(1): bools go as 1/0. */
    protected function prepareBindings(array $bindings): array
    {
        return array_map(static fn (mixed $v): mixed => is_bool($v) ? (int) $v : $v, $bindings);
    }
}
