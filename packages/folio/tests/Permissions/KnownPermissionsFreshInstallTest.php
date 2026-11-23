<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Permissions;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Data\Migration\MigrationRegistry;
use Preflow\Folio\Permissions\KnownPermissions;
use Preflow\Folio\Rbac\Extension;
use Preflow\Folio\Tests\Support\PublishingScaffold;

/**
 * A new project (create-project runs `preflow migrate` on SQL storage with the rbac extension on) does
 * not announce "Redirects"; a site that had roles before does. `preflow migrate` creates the role table
 * (folio.tables) before the known-permissions list is first read and the seed that gives a new site
 * its first role runs after, so only stored roles tell an upgrade from a new install.
 */
final class KnownPermissionsFreshInstallTest extends TestCase
{
    use PublishingScaffold;

    protected function setUp(): void
    {
        $this->scaffoldPublishing('sqlite');
        // The project's data is in SQLite (starter-folio): the role table is made by folio.tables.
        $this->write('config/data.php', "<?php\nreturn ['drivers' => ['sqlite' => ['path' => __DIR__ . '/../storage/app.sqlite']], 'default' => 'sqlite', 'models_path' => __DIR__ . '/models'];\n");
        $this->write('vendor/composer/installed.json', (string) json_encode(['packages' => [['name' => 'preflow/rbac']]]));
        $this->write('config/folio/extensions.json', '{"enabled": {"rbac": {"disabled_parts": []}}}');
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Rbac\\RbacServiceProvider::class];\n");
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    /** @return array<string, mixed> every step's lines, run in `preflow migrate`'s order */
    private function migrate(Application $app): array
    {
        $lines = [];
        foreach ($app->container()->get(MigrationRegistry::class)->steps() as $name => $step) {
            $lines[$name] = $step();
        }

        return $lines;
    }

    public function test_a_new_install_announces_nothing_through_every_migrate_step(): void
    {
        $app = Application::create($this->dir);
        $app->boot();
        $c = $app->container();

        $lines = $this->migrate($app);
        $this->assertSame([], $lines['folio.permissions']);

        $c->get(Extension::class)->seed($c); // the first role, after the steps (rbac:install / extensions:apply)
        $this->assertSame([], $c->get(KnownPermissions::class)->fresh());
    }

    public function test_an_upgrade_with_roles_announces_redirects(): void
    {
        $app = Application::create($this->dir);
        $app->boot();
        $c = $app->container();
        $this->migrate($app); // the tables are there
        $c->get(Extension::class)->seed($c); // roles from before
        // Folio never looked at its known list before (an update brought it): nothing is known yet.
        $c->get(\Preflow\Data\DataManager::class)->queryIn(KnownPermissions::definition())->get();
        foreach ($c->get(\Preflow\Data\DataManager::class)->queryIn(KnownPermissions::definition())->get()->items() as $row) {
            $c->get(\Preflow\Data\DataManager::class)->deleteIn(KnownPermissions::definition(), (string) $row->getId());
        }
        $app = Application::create($this->dir);
        $app->boot();
        $known = $app->container()->get(KnownPermissions::class);

        $this->assertSame(['redirects.manage'], $known->fresh());
    }
}
