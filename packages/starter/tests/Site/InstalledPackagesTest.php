<?php

declare(strict_types=1);

namespace Tests\Site;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Composer\InstalledPackages;
use Tests\Support\StarterApp;

/**
 * A test's copy of the project sees the packages the project has installed, so what a package
 * brings by being installed (translations, commands, Folio field types and blocks) is there in
 * tests too, not only on the site.
 */
final class InstalledPackagesTest extends TestCase
{
    use StarterApp;

    protected function setUp(): void
    {
        $this->bootStarter();
    }

    protected function tearDown(): void
    {
        $this->removeStarter();
    }

    public function test_the_copy_sees_the_packages_the_project_sees(): void
    {
        $project = array_column(InstalledPackages::read(dirname(__DIR__, 2) . '/vendor/composer/installed.json'), 'name');
        $copy = array_column($this->app()->installedPackages(), 'name');

        $this->assertContains('preflow/core', $project);
        $this->assertSame($project, $copy);
    }
}
