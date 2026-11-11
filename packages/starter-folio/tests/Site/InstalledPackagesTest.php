<?php

declare(strict_types=1);

namespace Tests\Site;

use Acme\TinyFieldType\StarsFieldType;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Composer\InstalledPackages;
use Preflow\Folio\Field\FieldTypeRegistry;
use Tests\Support\StarterApp;

/**
 * A test's copy of the project sees the packages the project has installed, so what a package
 * brings by being installed (Folio field types and blocks, translations, commands) is there in
 * tests too, not only on the site.
 */
final class InstalledPackagesTest extends TestCase
{
    use StarterApp {
        projectVendor as private realProjectVendor;
    }

    /** A vendor folder this test installs a package into, or null for the project's own. */
    private ?string $vendor = null;

    /** @var list<\Closure(string): void> the class loaders vendorWith() registered */
    private array $loaders = [];

    protected function tearDown(): void
    {
        $this->removeStarter();
        foreach ($this->loaders as $loader) {
            spl_autoload_unregister($loader);
        }
        $this->loaders = [];
        if ($this->vendor !== null) {
            exec('rm -rf ' . escapeshellarg($this->vendor));
            $this->vendor = null;
        }
    }

    private function projectVendor(): string
    {
        return $this->vendor ?? $this->realProjectVendor();
    }

    public function test_the_copy_sees_the_packages_the_project_sees(): void
    {
        $this->bootStarter();

        $project = array_column(InstalledPackages::read(dirname(__DIR__, 2) . '/vendor/composer/installed.json'), 'name');
        $copy = array_column($this->app()->installedPackages(), 'name');

        $this->assertContains('preflow/folio', $project);
        $this->assertSame($project, $copy);
    }

    public function test_a_field_type_an_installed_package_declares_is_resolved_in_the_copy(): void
    {
        $this->vendor = $this->vendorWith(dirname(__DIR__) . '/Fixtures/tiny-field-type');
        $this->bootStarter();

        $type = $this->container()->get(FieldTypeRegistry::class)->get('acme_stars');

        $this->assertInstanceOf(StarsFieldType::class, $type, 'not the string fallback');
    }

    /**
     * The project's vendor folder with one more package installed the way Composer installs a
     * path repository (a link in vendor/, an entry in vendor/composer/installed.json), and its
     * class loadable as Composer's autoloader would load it.
     */
    private function vendorWith(string $package): string
    {
        $real = $this->realProjectVendor();
        $vendor = sys_get_temp_dir() . '/starter_vendor_' . bin2hex(random_bytes(4));
        mkdir($vendor . '/composer', 0777, true);
        foreach (scandir($real) ?: [] as $entry) {
            if (!in_array($entry, ['.', '..', 'composer'], true)) {
                symlink($real . '/' . $entry, $vendor . '/' . $entry);
            }
        }

        $composer = json_decode((string) file_get_contents($package . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        [$owner] = explode('/', $composer['name']);
        mkdir($vendor . '/' . $owner);
        symlink($package, $vendor . '/' . $composer['name']);

        $installed = json_decode((string) file_get_contents($real . '/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
        $installed['packages'][] = $composer + ['version' => '1.0.0', 'install-path' => '../' . $composer['name']];
        file_put_contents($vendor . '/composer/installed.json', json_encode($installed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        foreach ($composer['autoload']['psr-4'] as $prefix => $dir) {
            $loader = static function (string $class) use ($prefix, $package, $dir): void {
                if (str_starts_with($class, $prefix)) {
                    require_once $package . '/' . $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                }
            };
            spl_autoload_register($loader);
            $this->loaders[] = $loader;
        }

        return $vendor;
    }
}
