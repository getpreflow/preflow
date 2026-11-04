<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Permissions;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Data\Migration\MigrationRegistry;
use Preflow\Folio\Permissions\KnownPermissions;
use Preflow\Folio\Tests\Support\PublishingScaffold;

/** `preflow migrate` through a booted app: the folio.permissions step sees Folio's declared permissions. */
final class KnownPermissionsMigrateTest extends TestCase
{
    use PublishingScaffold;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    public function test_migrate_lists_the_new_permissions_after_creating_the_table(): void
    {
        $app = Application::create($this->dir);
        $app->boot();
        $steps = $app->container()->get(MigrationRegistry::class)->steps();
        $names = array_keys($steps);
        $this->assertContains('folio.permissions', $names);
        $this->assertGreaterThan(array_search('folio.tables', $names, true), array_search('folio.permissions', $names, true), 'after the tables exist');

        // In MigrateCommand's order: every step, then once more (nothing changed in between).
        $lines = [];
        foreach ($steps as $name => $step) {
            $lines[$name] = $step();
        }
        $this->assertSame(['New permissions to review in Settings → Roles: redirects.manage (Redirects)'], $lines['folio.permissions']);
        $this->assertSame(['redirects.manage'], $app->container()->get(KnownPermissions::class)->fresh());
        $this->assertSame($lines['folio.permissions'], $steps['folio.permissions']());
    }
}
