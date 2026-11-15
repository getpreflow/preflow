<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Rbac;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Access\Access;
use Preflow\Auth\Tests\Fixtures\TestUser;
use Preflow\DevTools\Command\MigrateCommand;
use Preflow\Rbac\RbacResolver;
use Preflow\Rbac\RoleStore;

/**
 * A Folio site with the rbac extension on and its roles on SQLite: `preflow migrate` alone
 * (Folio's table sync makes rbac_role, rbac's "rbac.seed" step seeds it) installs rbac.
 */
final class FolioMigrateSeedTest extends TestCase
{
    use RbacAppScaffold;

    protected function setUp(): void
    {
        $this->scaffold();
        $this->write('config/data.php', "<?php\nreturn " . var_export([
            'default' => 'sqlite',
            'models_path' => $this->dir . '/config/models',
            'drivers' => [
                'sqlite' => ['driver' => \Preflow\Data\Driver\SqliteDriver::class, 'path' => $this->dir . '/storage/site.sqlite'],
                'json' => ['driver' => \Preflow\Data\Driver\JsonFileDriver::class, 'path' => $this->dir . '/storage/data'],
            ],
        ], true) . ";\n");
        $this->write('config/roles.php', "<?php\nreturn ['staff' => ['editor' => ['page.*']]];\n");
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    #[RunInSeparateProcess]
    public function test_migrate_alone_creates_the_table_and_seeds_the_admin(): void
    {
        ob_start();
        try {
            $code = (new MigrateCommand(fn () => $this->session ??= $this->app()))->execute([]);
        } finally {
            $out = (string) ob_get_clean();
        }
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('seeded 2 rbac roles', $out);

        $c = $this->container();
        $this->assertTrue($c->get(RoleStore::class)->installed());
        $this->assertSame(['admin' => ['*'], 'editor' => ['page.*']], $c->get(RoleStore::class)->rolesFor('staff'));
        $resolver = (new \ReflectionProperty(Access::class, 'resolver'))->getValue($c->get(Access::class));
        $this->assertInstanceOf(RbacResolver::class, $resolver);
        $this->assertTrue($resolver->allows(new TestUser(roles: ['admin']), 'staff', 'rbac_role.edit', null));
        $this->assertFalse($resolver->allows(new TestUser(roles: []), 'staff', 'page.edit', null), 'installed: no bootstrap');
        $this->assertFalse($resolver->allows(new TestUser(roles: ['editor']), 'staff', 'rbac_role.edit', null));
    }

    /**
     * JSON storage only: no schema to migrate, but the storage-neutral steps run (@since 0.77.1):
     * where rbac is installed (its rbac_role directory), migrate seeds config/roles.php.
     */
    #[RunInSeparateProcess]
    public function test_migrate_on_json_storage_only_seeds_the_roles_and_skips_the_table_steps(): void
    {
        $this->write('config/data.php', "<?php\nreturn " . var_export([
            'default' => 'json',
            'models_path' => $this->dir . '/config/models',
            'drivers' => [
                'json' => ['driver' => \Preflow\Data\Driver\JsonFileDriver::class, 'path' => $this->dir . '/storage/data'],
            ],
        ], true) . ";\n");
        mkdir($this->dir . '/storage/data/rbac_role', 0777, true); // installed (rbac:install's first write makes it)
        ob_start();
        try {
            $code = (new MigrateCommand(fn () => $this->session ??= $this->app()))->execute([]);
        } finally {
            $out = (string) ob_get_clean();
        }
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('No SQL storage configured: no schema migrations.', $out);
        $this->assertStringContainsString('seeded 2 rbac roles', $out);
        $this->assertSame(['admin' => ['*'], 'editor' => ['page.*']], $this->container()->get(RoleStore::class)->rolesFor('staff'));

        $registry = $this->container()->get(\Preflow\Data\Migration\MigrationRegistry::class);
        foreach (['folio.tables', 'activity.table'] as $sqlOnly) {
            $this->assertTrue($registry->isSqlOnly($sqlOnly), $sqlOnly);
        }
        foreach (['rbac.seed', 'folio.permissions', 'folio.addresses'] as $anyStorage) {
            $this->assertFalse($registry->isSqlOnly($anyStorage), $anyStorage);
        }
    }
}
