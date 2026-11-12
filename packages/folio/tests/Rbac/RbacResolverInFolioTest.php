<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Rbac;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Access\Access;
use Preflow\Auth\Access\PermissionResolver;
use Preflow\Core\Application;
use Preflow\Rbac\RbacResolver;
use Preflow\Roles\ConfigRoleResolver;

/** rbac's resolver on a Folio site: Folio's built-in extension "rbac" only shows or hides the admin. */
final class RbacResolverInFolioTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rbac_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    /** @return array<string, array{bool}> */
    public static function extensionStates(): array
    {
        return ['enabled' => [true], 'disabled' => [false]];
    }

    /** rbac answers when its provider runs; Folio's extension switch only shows or hides its admin. */
    #[RunInSeparateProcess]
    #[DataProvider('extensionStates')]
    public function test_resolver_whether_or_not_the_folio_extension_is_enabled(bool $enabled): void
    {
        $this->assertInstanceOf(RbacResolver::class, $this->resolverOf($this->app($enabled)));
    }

    #[RunInSeparateProcess]
    public function test_without_a_data_config_preflow_roles_keeps_answering(): void
    {
        $app = $this->app(true, data: false);

        $this->assertInstanceOf(ConfigRoleResolver::class, $this->resolverOf($app));
    }

    private function resolverOf(Application $app): PermissionResolver
    {
        $access = $app->container()->get(Access::class);
        $property = new \ReflectionProperty(Access::class, 'resolver');

        return $property->getValue($access);
    }

    private function app(bool $enabled, bool $data = true): Application
    {
        $w = function (string $rel, string $content): void {
            $path = $this->dir . '/' . $rel;
            @mkdir(dirname($path), 0777, true);
            file_put_contents($path, $content);
        };
        $w('config/app.php', "<?php\nreturn ['name'=>'Rbac Test','debug'=>0,'engine'=>'twig','key'=>'rbac-test-key'];\n");
        // preflow/roles registers its resolver in register(); rbac wins in boot().
        $w('config/providers.php', "<?php\nreturn [\\Preflow\\Roles\\RolesServiceProvider::class, \\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Rbac\\RbacServiceProvider::class];\n");
        $w('config/roles.php', "<?php\nreturn ['staff' => ['editor' => ['page.*']]];\n");
        $w('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'admin',
            'guards' => ['admin' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'config', 'population' => 'staff']],
            'providers' => ['config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => []]],
            'session' => ['cookie' => 'rbac_test', 'secure' => false],
        ], true) . ";\n");
        if ($data) {
            $w('config/data.php', "<?php\nreturn " . var_export([
                'default' => 'json',
                'models_path' => $this->dir . '/config/models',
                'drivers' => ['json' => ['path' => $this->dir . '/storage/data']],
            ], true) . ";\n");
        }
        $w('config/models/.keep', '');
        $w('vendor/composer/installed.json', (string) json_encode(['packages' => [
            ['name' => 'preflow/rbac'],
        ]]));
        if ($enabled) {
            $w('config/folio/extensions.json', '{"enabled": {"rbac": {"disabled_parts": []}}}');
        }
        $w('app/pages/index.twig', 'HOME');

        $app = Application::create($this->dir);
        $app->boot();

        return $app;
    }
}
