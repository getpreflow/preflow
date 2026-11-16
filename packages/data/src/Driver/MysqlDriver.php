<?php

declare(strict_types=1);

namespace Preflow\Data\Driver;

use Preflow\Data\Migration\Grammar\MysqlGrammar;
use Preflow\Data\RankedSearch;

final class MysqlDriver extends PdoDriver implements RankedSearch
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

    /** false until matchCollation() asked the server (or the constructor was told) */
    private bool $collationKnown = false;

    private ?string $matchCollation = null;

    /**
     * @param \PDO|\Closure(): \PDO $pdo A connection, or a closure that opens it on the first query.
     * @param string|false|null $matchCollation The collation LIKE and translatable text values
     *     compare with: null asks the server (matchCollation(), once, on first use), false takes
     *     the LOWER() fallback, a string names it.
     */
    public function __construct(\PDO|\Closure $pdo, ?\Preflow\Core\Debug\DebugCollector $collector = null, string|false|null $matchCollation = null)
    {
        if ($matchCollation !== null) {
            $this->matchCollation = $matchCollation === false ? null : $matchCollation;
            $this->collationKnown = true;
        }
        $dialect = new MysqlDialect($this->collationKnown ? $this->matchCollation : $this->matchCollation(...));
        parent::__construct($pdo, $dialect, new QueryCompiler($dialect, $this->columnFacts(...)), $collector);
    }

    /**
     * What the compiler needs to know of a table's columns (QueryCompiler's $columns), read once
     * per table from information_schema: so a query sorts a VARCHAR text field by its column (and
     * index) and compares a list in a full-text index exactly, whether QueryBuilder made it or
     * someone built it by hand. Empty for a table that is not there.
     *
     * @return array<string, array{bounded: bool, rule: bool}>
     */
    private function columnFacts(string $table): array
    {
        $stmt = $this->pdo()->prepare('SELECT COLUMN_NAME, DATA_TYPE, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        $facts = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_NUM) as [$name, $type, $collation]) {
            $facts[(string) $name] = [
                'bounded' => in_array(strtolower((string) $type), ['varchar', 'char'], true),
                'rule' => $collation !== null && in_array(strtolower((string) $collation), MysqlDialect::MATCH_COLLATIONS, true),
            ];
        }

        return $facts;
    }

    /**
     * The text rule's collation (one of MysqlDialect::MATCH_COLLATIONS): LIKE, NOT LIKE, CONTAINS
     * and search() compare with it, as the columns of text fields do (MysqlGrammar names the same
     * one); null when the server has none: LIKE then lowers both sides and the columns stay exact
     * (see the data README, "Text matching" and "Exact comparisons"). The server is probed once
     * per connection (MysqlGrammar::on()).
     *
     * @since 0.78
     */
    public function matchCollation(): ?string
    {
        if (!$this->collationKnown) {
            $this->matchCollation = (new MysqlGrammar())->on($this->pdo())->textCollation();
            $this->collationKnown = true;
        }

        return $this->matchCollation;
    }

    protected function configure(\PDO $pdo): void
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec("SET NAMES 'utf8mb4'");
        $pdo->exec("SET SESSION sql_mode = '" . self::SQL_MODE . "'");
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

    /** Ids per score() statement. */
    private const int SCORE_CHUNK = 500;

    /** @var array<string, bool> canScore() answers, by table and sorted fields */
    private array $scorable = [];

    /**
     * True when the table has a FULLTEXT index over exactly $fields (as a set); asked of
     * information_schema once per table and fields for this driver.
     */
    public function canScore(string $table, array $fields): bool
    {
        $wanted = array_values(array_unique($fields));
        sort($wanted);
        $key = $table . '|' . implode(',', $wanted);
        if (isset($this->scorable[$key])) {
            return $this->scorable[$key];
        }
        $found = false;
        if ($wanted !== []) {
            foreach ((new MysqlGrammar())->fullTextIndexes($this->pdo(), $table) as $columns) {
                sort($columns);
                if ($columns === $wanted) {
                    $found = true;
                    break;
                }
            }
        }

        return $this->scorable[$key] = $found;
    }

    /**
     * MATCH (fields) AGAINST (term IN NATURAL LANGUAGE MODE) for the given ids, in chunks. A
     * failure (the index dropped since canScore() looked) forgets the table's canScore() answers.
     */
    public function score(string $table, string $term, array $fields, array $ids, string $idField = 'uuid'): array
    {
        try {
            return $this->scoreChunks($table, $term, $fields, $ids, $idField);
        } catch (\PDOException $e) {
            $this->scorable = array_filter($this->scorable, static fn (string $key): bool => !str_starts_with($key, $table . '|'), ARRAY_FILTER_USE_KEY);
            throw $e;
        }
    }

    /**
     * @param list<string> $fields
     * @param list<string|int> $ids
     * @return array<string|int, float>
     */
    private function scoreChunks(string $table, string $term, array $fields, array $ids, string $idField): array
    {
        $scores = [];
        $quote = $this->dialect()->quoteIdentifier(...);
        $match = 'MATCH (' . implode(', ', array_map($quote, $fields)) . ') AGAINST (? IN NATURAL LANGUAGE MODE)';
        $keys = array_values(array_unique(array_map(strval(...), $ids)));
        foreach (array_chunk($keys, self::SCORE_CHUNK) as $chunk) {
            $sql = 'SELECT ' . $quote($idField) . ', ' . $match . ' FROM ' . $quote($table)
                . ' WHERE ' . $quote($idField) . ' IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')';
            $stmt = $this->pdo()->prepare($sql);
            $this->executeWithLogging($stmt, $sql, [$term, ...$chunk]);
            foreach ($stmt->fetchAll(\PDO::FETCH_NUM) as [$id, $score]) {
                $scores[(string) $id] = (float) $score;
            }
        }
        $result = [];
        foreach ($ids as $id) {
            $result[$id] = $scores[(string) $id] ?? 0.0;
        }

        return $result;
    }
}
