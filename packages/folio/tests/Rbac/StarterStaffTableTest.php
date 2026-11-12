<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Rbac;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\DevTools\Command\MigrateCommand;
use Preflow\Folio\Console\ExtensionsApplyCommand;

/**
 * preflow/starter-folio's own staff table (its migration, copied below) has the columns of rbac's
 * staff type, so a site that switches its staff provider to rbac's accounts keeps the table and
 * its accounts: the table sync adds no column for the staff fields (only Folio's own _columns), and
 * an existing account signs in and is listed.
 */
final class StarterStaffTableTest extends TestCase
{
    use RbacAppScaffold;

    /** packages/starter-folio/migrations/2026_11_09_004_create_staff.php, as the starter ships it. */
    private const string STARTER_MIGRATION = <<<'PHP'
        <?php
        use Preflow\Data\Migration\Migration;
        use Preflow\Data\Migration\Schema;
        use Preflow\Data\Migration\Table;
        return new class extends Migration
        {
            public function up(Schema $schema): void
            {
                $schema->create('staff', function (Table $table) {
                    $table->uuid('uuid')->primary();
                    $table->string('email')->nullable()->unique();
                    $table->string('name')->nullable();
                    $table->string('password_hash')->nullable();
                    $table->text('roles')->nullable();
                    $table->boolean('active')->nullable();
                    $table->string('auth_epoch')->nullable();
                    $table->string('last_login_at')->nullable();
                    $table->string('created_at')->nullable();
                });
            }

            public function down(Schema $schema): void
            {
                $schema->drop('staff');
            }
        };
        PHP;

    protected function setUp(): void
    {
        $this->scaffold();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    #[RunInSeparateProcess]
    public function test_the_starter_s_staff_table_serves_rbac_s_staff_accounts_unchanged(): void
    {
        $db = $this->dir . '/storage/site.sqlite';
        $this->write('config/data.php', "<?php\nreturn " . var_export([
            'default' => 'sqlite',
            'models_path' => $this->dir . '/config/models',
            'drivers' => ['json' => ['driver' => \Preflow\Data\Driver\JsonFileDriver::class, 'path' => $this->dir . '/storage/data'], 'sqlite' => ['path' => $db]],
        ], true) . ";\n");
        $this->write('migrations/2026_11_09_004_create_staff.php', self::STARTER_MIGRATION);
        // The starter's account, made before the switch (staff:create or its demo seed).
        $this->runCommand(new MigrateCommand(fn () => $this->app()));
        session_write_close(); // that app's session: the switched app below starts its own
        (new \PDO('sqlite:' . $db))->exec("INSERT INTO staff (uuid, email, name, password_hash, roles, active, auth_epoch, created_at) VALUES ('ada-1', 'ada@site.test', 'Ada', '"
            . password_hash('secret', PASSWORD_DEFAULT) . "', '[\"admin\"]', 1, '0', '2026-09-01 08:00:00')");
        $columns = $this->columns($db);

        // The switch: rbac's staff accounts, and the staff guard reads them.
        $this->write('config/folio/extensions.json', '{"enabled": {"rbac": {"disabled_parts": [], "enabled_parts": ["model:staff"]}}}');
        $auth = require $this->dir . '/config/auth.php';
        $auth['guards']['session']['provider'] = 'staff';
        $auth['providers']['staff'] = ['class' => \Preflow\Auth\Record\RecordUserProvider::class, 'type' => 'staff', 'active' => 'active'];
        $this->write('config/auth.php', "<?php\nreturn " . var_export($auth, true) . ";\n");
        $app = fn (): \Preflow\Core\Application => $this->session ??= $this->app();
        $this->runCommand(new MigrateCommand($app));
        $this->runCommand(new ExtensionsApplyCommand($app));

        // Only Folio's own columns (_version, _locales, …) are added: none for the staff fields.
        $this->assertSame($columns, array_values(array_filter($this->columns($db), static fn (string $c): bool => !str_starts_with($c, '_'))));
        $this->as('ada');
        $list = $this->send('GET', '/folio/staff');
        $this->assertSame(200, $list->getStatusCode(), (string) $list->getBody());
        $this->assertStringContainsString('ada@site.test', (string) $list->getBody());
    }

    private function runCommand(\Preflow\DevTools\Command\CommandInterface $command): void
    {
        ob_start();
        try {
            $code = $command->execute([]);
        } finally {
            $out = (string) ob_get_clean();
        }
        $this->assertSame(0, $code, $out);
    }

    /** @return list<string> */
    private function columns(string $db): array
    {
        return array_column((new \PDO('sqlite:' . $db))->query('PRAGMA table_info(staff)')->fetchAll(\PDO::FETCH_ASSOC), 'name');
    }
}
