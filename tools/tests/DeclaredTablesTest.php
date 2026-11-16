<?php

declare(strict_types=1);

namespace Preflow\Tools\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Codes\CodesServiceProvider;
use Preflow\Core\Application;
use Preflow\Data\Driver\SqliteDialect;
use Preflow\Data\Migration\MigrationColumns;
use Preflow\Data\Migration\Migrator;
use Preflow\Data\Schema\DeclaredTables;

/**
 * The framework declares its tables' column intent (DeclaredTables) for the tables made before
 * 0.78, and its packages' migrations say the same with ->exact() for the tables made since: a
 * fresh migrate and a converted old table end up alike only when both agree. Every declared
 * table a package migration makes must record exactly the intent declared.
 */
final class DeclaredTablesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/declared_tables_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name' => 't', 'debug' => 0, 'key' => 'a-real-secret-key'];\n");
        file_put_contents($this->dir . '/config/data.php', '<?php return ["default" => "sqlite", "drivers" => ["sqlite" => ["path" => ' . var_export($this->dir . '/app.sqlite', true) . ']]];');
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    public function test_declared_intent_matches_what_the_package_migrations_record(): void
    {
        file_put_contents($this->dir . '/config/providers.php', "<?php\nreturn [\\" . CodesServiceProvider::class . "::class];\n");
        $app = Application::create($this->dir);
        $app->boot();
        $declared = $app->container()->get(DeclaredTables::class);

        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        SqliteDialect::prepare($pdo);
        $paths = array_values(array_filter(
            glob(dirname(__DIR__, 2) . '/packages/*/migrations') ?: [],
            static fn (string $path): bool => !str_starts_with(basename(dirname($path)), 'starter'),
        ));
        (new Migrator($pdo, $paths))->migrate();
        $recorded = (new MigrationColumns($pdo))->tables();

        $grammar = \Preflow\Data\Migration\Grammar\Grammars::for($pdo);
        $checked = 0;
        foreach ($declared->all() as $entry) {
            if (!isset($recorded[$entry['table']])) {
                continue; // made by the migrator itself (its bookkeeping), not by a migration's Schema
            }
            // The columns that hold text (a number has no collation, so no intent to declare).
            $text = array_filter($grammar->columnCollations($pdo, $entry['table']), static fn (?string $c): bool => $c !== null);
            $this->assertSame(
                self::sorted(array_intersect_key($recorded[$entry['table']], $text)),
                self::sorted($entry['columns']),
                "{$entry['table']}: the migration's ->exact() and the declaration disagree",
            );
            $checked++;
        }
        $this->assertGreaterThanOrEqual(4, $checked, 'checked ' . $checked . ' of ' . implode(', ', array_column($declared->all(), 'table')) . '; recorded: ' . implode(', ', array_keys($recorded)));
    }

    /** @param array<string, string> $columns @return array<string, string> */
    private static function sorted(array $columns): array
    {
        ksort($columns);

        return $columns;
    }
}
