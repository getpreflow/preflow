<?php

declare(strict_types=1);

namespace Preflow\Rbac\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Access\Access;
use Preflow\Auth\Access\PermissionResolver;
use Preflow\Auth\Tests\Fixtures\TestUser;
use Preflow\Core\Application;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Extension\ExtensionState;
use Preflow\Rbac\RbacResolver;
use Preflow\Rbac\RoleStore;
use Preflow\Roles\ConfigRoleResolver;

final class RbacResolverTest extends TestCase
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

    /** @param list<array{string, string, mixed}> $roles population, name, permissions */
    private function resolver(array $roles, bool $installed = true): RbacResolver
    {
        $registry = new TypeRegistry(dirname(__DIR__) . '/src/models');
        $dm = new DataManager(drivers: ['default' => new JsonFileDriver($this->dir . '/store')], typeRegistry: $registry);
        $state = new ExtensionState($dm);
        if ($installed) {
            $state->markInstalled('rbac', '1.0.0');
        }
        foreach ($roles as $i => [$population, $name, $permissions]) {
            $dm->saveType(DynamicRecord::fromArray($registry->get('rbac_role'), [
                'uuid' => 'r' . $i, 'name' => $name, 'label' => ucfirst($name),
                'population' => $population, 'permissions' => $permissions,
            ]), validate: false);
        }

        return new RbacResolver(new RoleStore($dm), $state);
    }

    public function test_staff_role_pattern_grants(): void
    {
        $resolver = $this->resolver([['staff', 'editor', ['page.*', 'asset.view']]]);
        $editor = new TestUser(roles: ['editor']);

        $this->assertTrue($resolver->allows($editor, 'staff', 'page.edit', null));
        $this->assertTrue($resolver->allows($editor, 'staff', 'asset.view', null));
        $this->assertFalse($resolver->allows($editor, 'staff', 'rbac_role.edit', null));
    }

    public function test_member_role_pattern_grants_only_members_population(): void
    {
        $resolver = $this->resolver([
            ['staff', 'admin', ['*']],
            ['members', 'member', ['tools.*']],
        ]);
        $member = new TestUser(roles: ['member']);

        $this->assertTrue($resolver->allows($member, 'members', 'tools.use', null));
        $this->assertFalse($resolver->allows($member, 'staff', 'tools.use', null));
        // A staff role name held by a member grants nothing in the members population.
        $this->assertFalse($resolver->allows(new TestUser(roles: ['admin']), 'members', 'tools.use', null));
    }

    public function test_unknown_role_grants_nothing(): void
    {
        $resolver = $this->resolver([['staff', 'editor', ['page.*']]]);

        $this->assertFalse($resolver->allows(new TestUser(roles: ['ghost']), 'staff', 'page.edit', null));
        $this->assertFalse($resolver->allows(new TestUser(roles: []), 'staff', 'page.edit', null));
        // Members with no roles defined get nothing.
        $this->assertFalse($resolver->allows(new TestUser(roles: ['member']), 'members', 'tools.use', null));
    }

    public function test_roles_as_json_string_resolve(): void
    {
        // As SQL returns them: permissions and the user's roles are JSON strings.
        $resolver = $this->resolver([['members', 'member', '["tools.*"]']]);

        $this->assertTrue($resolver->allows(new TestUser(roles: ['["member"]']), 'members', 'tools.use', null));
        $this->assertFalse($resolver->allows(new TestUser(roles: ['["member"]']), 'members', 'page.edit', null));
    }

    public function test_no_staff_roles_yet_staff_may_do_everything(): void
    {
        // Enabled, but folio:extensions:apply has not installed it here yet.
        $resolver = $this->resolver([['members', 'member', ['tools.*']]], installed: false);
        $lines = $this->logged(function () use ($resolver): void {
            $this->assertTrue($resolver->allows(new TestUser(roles: ['admin']), 'staff', 'rbac_role.edit', null));
            $this->assertTrue($resolver->allows(new TestUser(roles: []), 'staff', 'page.edit', null));
            $this->assertFalse($resolver->allows(new TestUser(roles: []), 'members', 'tools.use', null));
        }, 'not installed');

        $this->assertCount(1, $lines, 'logged once per request');
        $this->assertStringContainsString('folio:extensions:apply', $lines[0]);
    }

    public function test_not_installed_staff_may_do_everything_even_with_staff_roles(): void
    {
        $resolver = $this->resolver([['staff', 'editor', ['page.*']]], installed: false);

        $this->logged(function () use ($resolver): void {
            $this->assertTrue($resolver->allows(new TestUser(roles: ['editor']), 'staff', 'rbac_role.edit', null));
        }, 'not installed');
    }

    public function test_after_install_empty_staff_roles_grant_nothing(): void
    {
        $resolver = $this->resolver([['members', 'member', ['tools.*']]]);
        $lines = $this->logged(function () use ($resolver): void {
            $this->assertFalse($resolver->allows(new TestUser(roles: ['admin']), 'staff', 'rbac_role.edit', null));
            $this->assertFalse($resolver->allows(new TestUser(roles: []), 'staff', 'page.edit', null));
            $this->assertTrue($resolver->allows(new TestUser(roles: ['member']), 'members', 'tools.use', null));
        }, 'no staff roles defined');

        $this->assertCount(1, $lines, 'logged once per request');
        $this->assertStringContainsString('run folio:extensions:apply or create one under Settings → Roles', $lines[0]);
    }

    /** @return list<string> the error_log lines containing $needle written while $run ran */
    private function logged(\Closure $run, string $needle): array
    {
        $log = $this->dir . '/error.log';
        $previous = ini_set('error_log', $log);
        try {
            $run();
        } finally {
            ini_set('error_log', (string) $previous);
        }

        return array_values(array_filter(explode("\n", (string) @file_get_contents($log)), fn ($l) => str_contains($l, $needle)));
    }

    /** @return array<string, array{bool, class-string}> */
    public static function extensionStates(): array
    {
        return [
            'enabled' => [true, RbacResolver::class],
            // Disabled: preflow/roles keeps answering.
            'disabled' => [false, ConfigRoleResolver::class],
        ];
    }

    #[RunInSeparateProcess]
    #[DataProvider('extensionStates')]
    public function test_resolver_only_when_extension_enabled(bool $enabled, string $resolver): void
    {
        $this->assertInstanceOf($resolver, $this->resolverOf($this->app($enabled)));
    }

    private function resolverOf(Application $app): PermissionResolver
    {
        $access = $app->container()->get(Access::class);
        $property = new \ReflectionProperty(Access::class, 'resolver');

        return $property->getValue($access);
    }

    private function app(bool $enabled): Application
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
        $w('config/data.php', "<?php\nreturn " . var_export([
            'default' => 'json',
            'models_path' => $this->dir . '/config/models',
            'drivers' => ['json' => ['path' => $this->dir . '/storage/data']],
        ], true) . ";\n");
        $w('config/models/.keep', '');
        $w('vendor/composer/installed.json', (string) json_encode(['packages' => [
            ['name' => 'preflow/rbac', 'extra' => ['folio' => ['extension' => 'Preflow\\Rbac\\Extension']]],
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
