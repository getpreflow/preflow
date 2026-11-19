<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

require_once __DIR__ . '/IntIdInsertScenarios.php';

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\Locales;
use Preflow\Data\StorageDriver;
use Preflow\Data\TypeRegistry;
use Preflow\Testing\PlainDriver;

/** insert() with a given int id on SQLite, JSON files and a driver with no capability at all. */
final class IntIdInsertTest extends TestCase
{
    use IntIdInsertScenarios;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/preflow_int_id_insert_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/json', 0755, true);
        mkdir($this->dir . '/types', 0755, true);
    }

    protected function tearDown(): void
    {
        $remove = static function (string $path) use (&$remove): void {
            if (is_dir($path) && !is_link($path)) {
                foreach (scandir($path) ?: [] as $entry) {
                    if ($entry !== '.' && $entry !== '..') {
                        $remove($path . '/' . $entry);
                    }
                }
                rmdir($path);
            } elseif (file_exists($path)) {
                unlink($path);
            }
        };
        $remove($this->dir);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function kindsAndModels(): iterable
    {
        foreach (['sqlite', 'json', 'plain'] as $kind) {
            yield "{$kind}, plain model" => [$kind, false];
            yield "{$kind}, class-backed type" => [$kind, true];
        }
    }

    protected function generatesIds(string $kind): bool
    {
        return $kind !== 'json';
    }

    protected function manager(string $kind, bool $typed = false): DataManager
    {
        $types = $typed ? new TypeRegistry($this->dir . '/types', new Locales(['en'], 'en'), ['typed_tallies' => TypedTallyRow::class]) : null;

        return new DataManager(['default' => $this->driver($kind)], 'default', $types);
    }

    private function driver(string $kind): StorageDriver
    {
        if ($kind === 'json') {
            return new JsonFileDriver($this->dir . '/json');
        }
        $pdo = new \PDO('sqlite:' . $this->dir . '/db.sqlite');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE IF NOT EXISTS tallies (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS typed_tallies (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT)');
        $sqlite = new SqliteDriver($pdo);

        return $kind === 'plain' ? new PlainDriver($sqlite) : $sqlite;
    }
}
