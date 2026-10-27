<?php

declare(strict_types=1);

namespace Preflow\Tools\Convert;

use Preflow\Core\Application;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\PdoDriver;
use Preflow\Data\Schema\RecordTables;

/**
 * php tools/convert-typed-columns.php <site-dir> [--check] [--storage=<driver>] [--sqlite=<file>] [--backup-dir=<dir>]
 *
 * Boots the site's app from its directory (with the site's own vendor/), takes the record tables
 * of one SQL storage from RecordTables and runs the conversion. --sqlite runs it against another
 * SQLite file (a copy) instead of the configured one, which is then never opened.
 */
final class Cli
{
    private const string USAGE = 'Usage: php tools/convert-typed-columns.php <site-dir> [--check] [--storage=<driver>] [--sqlite=<file>] [--backup-dir=<dir>]';

    /** @param list<string> $args the arguments after the script's name */
    public function run(array $args): int
    {
        $site = null;
        $check = false;
        $storage = null;
        $sqlite = null;
        $backupDir = null;
        foreach ($args as $arg) {
            if ($arg === '--check') {
                $check = true;
            } elseif (str_starts_with($arg, '--storage=')) {
                $storage = substr($arg, strlen('--storage='));
            } elseif (str_starts_with($arg, '--sqlite=')) {
                $sqlite = substr($arg, strlen('--sqlite='));
            } elseif (str_starts_with($arg, '--backup-dir=')) {
                $backupDir = rtrim(substr($arg, strlen('--backup-dir=')), '/');
            } elseif ($site === null && !str_starts_with($arg, '--')) {
                $site = $arg;
            } else {
                return $this->fail("Unknown argument {$arg}.\n" . self::USAGE);
            }
        }
        $site = $site === null ? false : realpath($site);
        if ($site === false || !is_dir($site)) {
            return $this->fail(self::USAGE);
        }
        if (!is_file("{$site}/vendor/autoload.php")) {
            return $this->fail("{$site} has no vendor/autoload.php: run composer install there first.");
        }
        if ($sqlite !== null && (($sqlite = realpath($sqlite)) === false || !is_file($sqlite))) {
            return $this->fail('--sqlite: no such file.');
        }
        require "{$site}/vendor/autoload.php";
        if (!class_exists(RecordTables::class)) {
            return $this->fail("The site's preflow/data has no RecordTables: update it to 0.42 or later first.");
        }

        $overlay = $sqlite !== null ? Overlay::create($site, $sqlite, sys_get_temp_dir()) : null;
        try {
            $base = $overlay?->basePath() ?? $site;
            $app = Application::create($base);
            $app->boot();
            if (!is_file("{$base}/config/data.php")) {
                $this->out('Nothing to convert: no SQL storage configured.');
                return 0;
            }
            $dm = $app->container()->get(DataManager::class);
            $storage ??= self::defaultStorage($dm);
            if ($storage === false) {
                $this->out('Nothing to convert: no SQL storage configured.');
                return 0;
            }
            if ($storage === null) {
                return $this->fail('Several SQL storages are configured; choose one with --storage=<driver>.');
            }
            $driver = $dm->driver($storage);
            if (!$driver instanceof PdoDriver) {
                return $this->fail("Storage [{$storage}] is not a SQL storage.");
            }
            $pdo = $driver->pdo();
            $mysql = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
            $database = $mysql
                ? 'MySQL database ' . $pdo->query('SELECT DATABASE()')->fetchColumn()
                : (string) $pdo->query('PRAGMA database_list')->fetch(\PDO::FETCH_ASSOC)['file'];
            if ($sqlite !== null && !$mysql && realpath($database) !== $sqlite) {
                return $this->fail("--sqlite: storage [{$storage}] opened {$database}, not {$sqlite}; stopping.");
            }
            $this->out("Site {$site}, storage [{$storage}]: {$database}");

            $tables = Tables::from($dm, $app->container()->get(RecordTables::class)->all(), $storage);
            $backup = new Backup($pdo, $backupDir ?? "{$site}/storage/backups", $mysql ? self::mysqlConfig($base) : []);

            return (new Conversion($tables, $backup))->run($check, $this->out(...));
        } catch (\Throwable $e) {
            return $this->fail('Error: ' . $e->getMessage());
        } finally {
            $overlay?->remove();
        }
    }

    /**
     * As `preflow migrate` picks it: the default storage when it is SQL, else the one SQL storage
     * (aliases of it count once); null when there are several, false when there is none.
     */
    private static function defaultStorage(DataManager $dm): string|false|null
    {
        if (in_array('default', $dm->driverNames(), true) && $dm->driver('default') instanceof PdoDriver) {
            return 'default';
        }
        $sql = [];
        foreach ($dm->driverNames() as $name) {
            $driver = $dm->driver($name);
            if ($driver instanceof PdoDriver && !in_array($driver, $sql, true)) {
                $sql[$name] = $driver;
            }
        }

        return match (count($sql)) {
            0 => false,
            1 => (string) array_key_first($sql),
            default => null,
        };
    }

    /**
     * The connection the app made (drivers.mysql with the DB_* fallbacks, as Application reads them), for mysqldump.
     *
     * @return array{host: string, port: string, database: string, username: string, password: string}
     */
    private static function mysqlConfig(string $base): array
    {
        $config = (require "{$base}/config/data.php")['drivers']['mysql'] ?? [];

        return [
            'host' => (string) ($config['host'] ?? getenv('DB_HOST') ?: '127.0.0.1'),
            'port' => (string) ($config['port'] ?? getenv('DB_PORT') ?: '3306'),
            'database' => (string) ($config['database'] ?? getenv('DB_NAME') ?: ''),
            'username' => (string) ($config['username'] ?? getenv('DB_USER') ?: 'root'),
            'password' => (string) ($config['password'] ?? getenv('DB_PASS') ?: ''),
        ];
    }

    private function out(string $line): void
    {
        fwrite(STDOUT, $line . "\n");
    }

    private function fail(string $message): int
    {
        fwrite(STDERR, $message . "\n");

        return 1;
    }
}
