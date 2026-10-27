<?php

declare(strict_types=1);

namespace Preflow\Tools\Convert;

/**
 * A copy of the whole database before converting, with a timestamp in its name: SQLite with
 * VACUUM INTO, MySQL with mysqldump (credentials in a temporary 0600 option file, never on the
 * command line). Nothing rotates these: a backup taken before an unfinished run stays.
 *
 * Restore: SQLite — replace the database file with the backup (site stopped or in maintenance);
 * MySQL — `mysql --defaults-extra-file=<option file> <database> < <backup>.sql`.
 */
final class Backup
{
    /**
     * @param array{host?: string, port?: string|int, database?: string, username?: string, password?: string} $mysql
     *        the connection for mysqldump (the site's drivers.mysql with the DB_* fallbacks)
     */
    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $dir,
        private readonly array $mysql = [],
    ) {}

    /** @return string the backup's path */
    public function create(): string
    {
        if (!is_dir($this->dir) && !mkdir($this->dir, 0750, true) && !is_dir($this->dir)) {
            throw new \RuntimeException("Cannot create the backup directory {$this->dir}.");
        }

        return $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? $this->mysqldump() : $this->vacuumInto();
    }

    private function vacuumInto(): string
    {
        $name = (string) $this->pdo->query('PRAGMA database_list')->fetch(\PDO::FETCH_ASSOC)['file'];
        $path = $this->path(pathinfo($name, PATHINFO_FILENAME) ?: 'database', 'sqlite');
        $this->pdo->exec('VACUUM INTO ' . $this->pdo->quote($path));

        return $path;
    }

    private function mysqldump(): string
    {
        $database = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        $binary = self::binary();
        $path = $this->path($database, 'sql');
        $options = tempnam(sys_get_temp_dir(), 'convert-my-');
        if ($options === false) {
            throw new \RuntimeException('Cannot create the temporary option file for mysqldump.');
        }
        try {
            chmod($options, 0600);
            $quote = static fn (string $v): string => '"' . addcslashes($v, "\\\"") . '"';
            file_put_contents($options, "[client]\n"
                . 'host=' . $quote((string) ($this->mysql['host'] ?? '127.0.0.1')) . "\n"
                . 'port=' . (int) ($this->mysql['port'] ?? 3306) . "\n"
                . 'user=' . $quote((string) ($this->mysql['username'] ?? 'root')) . "\n"
                . 'password=' . $quote((string) ($this->mysql['password'] ?? '')) . "\n");

            $process = proc_open(
                [$binary, '--defaults-extra-file=' . $options, '--single-transaction', '--no-tablespaces', '--result-file=' . $path, $database],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            if (!is_resource($process)) {
                throw new \RuntimeException("Cannot start {$binary}.");
            }
            stream_get_contents($pipes[1]);
            $error = trim((string) stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);
        } finally {
            unlink($options);
        }
        if ($status !== 0 || !is_file($path) || filesize($path) === 0) {
            if (is_file($path)) {
                unlink($path);
            }
            throw new \RuntimeException("{$binary} failed (exit {$status}): {$error}");
        }

        return $path;
    }

    private function path(string $name, string $extension): string
    {
        $base = $this->dir . '/' . preg_replace('/[^A-Za-z0-9_.-]+/', '-', $name) . '-before-typed-columns-' . date('Ymd-His');
        $path = "{$base}.{$extension}";
        for ($n = 2; file_exists($path); $n++) {
            $path = "{$base}-{$n}.{$extension}";
        }

        return $path;
    }

    private static function binary(): string
    {
        foreach (['mysqldump', 'mariadb-dump'] as $name) {
            $found = trim((string) shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
            if ($found !== '') {
                return $found;
            }
        }
        throw new \RuntimeException('Neither mysqldump nor mariadb-dump is installed.');
    }
}
