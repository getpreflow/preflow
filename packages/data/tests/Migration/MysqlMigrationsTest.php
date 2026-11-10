<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Migration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Preflow\Data\Driver\MysqlDriver;
use Preflow\Data\Migration\Migrator;
use Preflow\Data\Migration\Schema;
use Preflow\Data\Migration\Table;
use Preflow\Testing\MysqlTestDatabase;

#[Group('mysql')]
final class MysqlMigrationsTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = MysqlTestDatabase::pdo();
        MysqlTestDatabase::dropAllTables($this->pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            MysqlTestDatabase::dropAllTables($this->pdo);
        }
    }

    /**
     * The migrations of the library packages (a site runs them all, with its own). The app
     * templates (skeleton, starters: composer type "project") are left out: each is a site of its
     * own, so their migrations never run together and may share names.
     *
     * @return list<string>
     */
    private function paths(): array
    {
        return array_values(array_filter(glob(dirname(__DIR__, 3) . '/*/migrations') ?: [], static fn (string $dir): bool => !self::isAppTemplate(dirname($dir))));
    }

    /** @return list<string> the app templates that ship migrations */
    private static function appTemplates(): array
    {
        return array_values(array_filter(array_map(dirname(...), glob(dirname(__DIR__, 3) . '/*/migrations') ?: []), self::isAppTemplate(...)));
    }

    private static function isAppTemplate(string $package): bool
    {
        $composer = json_decode((string) @file_get_contents($package . '/composer.json'), true);

        return is_array($composer) && ($composer['type'] ?? 'library') === 'project';
    }

    public function test_every_package_migration_runs_on_mysql_twice(): void
    {
        $migrator = new Migrator($this->pdo, $this->paths());
        $migrator->migrate();

        $this->assertSame([], $migrator->pending());
        (new Migrator($this->pdo, $this->paths()))->migrate();

        $tables = $this->pdo->query(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
        )->fetchAll(\PDO::FETCH_COLUMN);
        foreach (['preflow_migrations', 'preflow_throttle', 'auth_tokens', 'auth_remember', 'code_redemption'] as $t) {
            $this->assertContains($t, $tables);
        }
    }

    public function test_each_app_template_migrates_on_mysql_twice_with_the_packages(): void
    {
        $templates = self::appTemplates();
        $this->assertContains('starter', array_map(basename(...), $templates));
        $this->assertContains('starter-folio', array_map(basename(...), $templates));

        foreach ($templates as $template) {
            MysqlTestDatabase::dropAllTables($this->pdo);
            $paths = [...$this->paths(), $template . '/migrations'];
            $migrator = new Migrator($this->pdo, $paths);
            $migrator->migrate();

            $this->assertSame([], $migrator->pending(), basename($template));
            (new Migrator($this->pdo, $paths))->migrate();
        }
    }

    public function test_introspection_and_add_column(): void
    {
        (new Migrator($this->pdo, $this->paths()))->migrate();
        $schema = new Schema($this->pdo);

        $this->assertTrue($schema->hasTable('code_redemption'));
        $this->assertFalse($schema->hasTable('nope'));
        $this->assertSame(['id', 'code_id', 'purpose', 'redeemer', 'redeemed_at'], $schema->columns('code_redemption'));

        $schema->addColumn('code_redemption', 'note');
        $columns = $schema->columns('code_redemption');
        $this->assertSame('note', end($columns));

        $type = $this->pdo->query(
            "SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() "
            . "AND TABLE_NAME = 'code_redemption' AND COLUMN_NAME = 'note'"
        )->fetchColumn();
        $this->assertSame('longtext', strtolower((string) $type));
    }

    public function test_indexing_a_text_column_throws(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot index TEXT column "t.body" on MySQL: use string() for indexed columns.');

        (new Schema($this->pdo))->create('t', fn (Table $t) => $t->uuid('id')->primary()->text('body')->index());
    }

    public function test_composite_index_with_text_column_throws(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot index TEXT column "t.body" on MySQL');

        (new Schema($this->pdo))->create('t', fn (Table $t) => $t->string('a')->text('body')->unique('a', 'body'));
    }

    public function test_composite_unique_on_mysql(): void
    {
        (new Schema($this->pdo))->create('t', fn (Table $t) => $t->string('player_token')->string('game')->unique('player_token', 'game'));
        $insert = $this->pdo->prepare('INSERT INTO t (player_token, game) VALUES (?, ?)');
        $insert->execute(['a', 'g1']);
        $insert->execute(['a', 'g2']);

        $this->assertContains('uniq_t_player_token_game', (new Schema($this->pdo))->indexes('t'));
        $this->expectException(\PDOException::class);
        $insert->execute(['a', 'g1']);
    }

    public function test_ids_differing_in_case_or_accent_coexist(): void
    {
        (new Schema($this->pdo))->create('t', fn (Table $t) => $t->uuid('id')->primary()->string('name'));
        $driver = new MysqlDriver($this->pdo);

        foreach (['A', 'a', 'ä'] as $id) {
            $driver->save('t', $id, ['name' => "name {$id}"], 'id');
        }

        $this->assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM `t`')->fetchColumn());
        foreach (['A', 'a', 'ä'] as $id) {
            $this->assertSame("name {$id}", $driver->findOne('t', $id, 'id')['name'] ?? null, $id);
        }
    }

    public function test_migration_names_compare_exactly(): void
    {
        (new Migrator($this->pdo, []))->pending();
        $insert = $this->pdo->prepare('INSERT INTO `preflow_migrations` (`migration`, `ran_at`) VALUES (?, ?)');
        $insert->execute(['2026_01_01_a', 'now']);
        $insert->execute(['2026_01_01_A', 'now']);

        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM `preflow_migrations`')->fetchColumn());
    }
}
