<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Permissions;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Data\Migration\MigrationRegistry;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Permissions\KnownPermissions;
use Preflow\Folio\Tests\Support\PublishingScaffold;

/**
 * `preflow migrate` through a booted app: the folio.permissions step sees Folio's declared
 * permissions, and prints them only where a Roles screen shows them (rbac; see rbac's
 * NewPermissionsTest for that case).
 */
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

    public function test_migrate_step_runs_after_the_tables_and_says_nothing_without_rbac(): void
    {
        $app = Application::create($this->dir);
        $app->boot();
        $steps = $app->container()->get(MigrationRegistry::class)->steps();
        $names = array_keys($steps);
        $this->assertContains('folio.permissions', $names);
        $this->assertGreaterThan(array_search('folio.tables', $names, true), array_search('folio.permissions', $names, true), 'after the tables exist');
        $this->assertFalse($app->container()->get(TypeCatalog::class)->has('rbac_role'), 'a Folio site without rbac');

        // In MigrateCommand's order: every step, then once more. No Roles screen to review them in, so no line.
        $lines = [];
        foreach ($steps as $name => $step) {
            $lines[$name] = $step();
        }
        $this->assertSame([], $lines['folio.permissions']);
        $this->assertSame([], $steps['folio.permissions']());
        $this->assertSame(['redirects.manage'], $app->container()->get(KnownPermissions::class)->fresh(), 'the list itself works');
    }
}
