<?php

declare(strict_types=1);

namespace Preflow\Data\Driver;

final class SqliteDriver extends PdoDriver
{
    public function __construct(\PDO $pdo, ?\Preflow\Core\Debug\DebugCollector $collector = null)
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $dialect = new SqliteDialect();
        parent::__construct($pdo, $dialect, new QueryCompiler($dialect), $collector);
    }

    /** Whether exclusive() has its BEGIN IMMEDIATE open (PDO before PHP 8.5 does not report it). */
    private bool $immediate = false;

    /**
     * Inside BEGIN IMMEDIATE: the database's write lock, taken up front, so no other connection
     * writes until $fn is done and committed. A caller's open transaction is used as it is.
     * The transaction is tracked here, not by PDO::inTransaction(), which reports one opened
     * with exec() only from PHP 8.5.
     */
    public function exclusive(string $type, \Closure $fn): mixed
    {
        if ($this->inTransaction()) {
            return $fn();
        }
        $this->pdo->exec('BEGIN IMMEDIATE');
        $this->immediate = true;
        try {
            $result = $fn();
        } catch (\Throwable $e) {
            $this->immediate = false;
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (\PDOException) {
                // Already rolled back by SQLite; the original failure is what matters.
            }
            throw $e;
        }
        $this->immediate = false;
        try {
            $this->pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (\PDOException) {
            }
            throw $e;
        }

        return $result;
    }

    protected function inTransaction(): bool
    {
        return $this->immediate || parent::inTransaction();
    }
}
