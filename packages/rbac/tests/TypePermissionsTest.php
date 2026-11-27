<?php

declare(strict_types=1);

namespace Preflow\Rbac\Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Access\Access;
use Preflow\Auth\Access\PermissionRegistry;
use Preflow\Auth\Access\PermissionResolver;
use Preflow\Auth\AuthManager;
use Preflow\Auth\Authenticatable;
use Preflow\Auth\Tests\Fixtures\TestUser;
use Preflow\Core\Application;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\Viewer;
use Preflow\Data\TypeAction;
use Preflow\Data\TypeRegistry;
use Preflow\Rbac\TypePermissions;
use Preflow\Testing\ChecksFrameBalance;

/**
 * The per-type permission names ("{type}.view|create|edit|publish|delete") in rbac: a site's API
 * controller checks them without Folio (allows(): the user's permission and the type's action),
 * rbac declares them for every type at boot, and Folio's catalog labels win in either boot order.
 */
final class TypePermissionsTest extends TestCase
{
    use ChecksFrameBalance;

    private string $dir = '';
    private string|false $errorLog = false;

    protected function tearDown(): void
    {
        if ($this->errorLog !== false) {
            ini_set('error_log', (string) $this->errorLog);
        }
        if ($this->dir === '' || !is_dir($this->dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    public function test_names_and_verbs(): void
    {
        $this->assertSame('job.publish', TypePermissions::name('job', 'publish'));
        $this->assertSame([
            'create' => TypeAction::Create,
            'edit' => TypeAction::Update,
            'publish' => TypeAction::Update,
            'delete' => TypeAction::Delete,
        ], TypePermissions::VERBS);
    }

    public function test_allows_needs_the_permission_and_the_type_action(): void
    {
        $this->dir = sys_get_temp_dir() . '/rbac_type_permissions_' . bin2hex(random_bytes(4));
        $this->model('faq', ['readonly' => true]);
        $this->model('inbox', ['creatable' => false]);
        $this->model('job');
        $types = new TypeRegistry($this->dir . '/config/models');
        $registry = new PermissionRegistry();
        foreach (['faq', 'inbox', 'job'] as $type) {
            TypePermissions::declare($registry, $type, ucfirst($type));
        }

        Contexts::run(\Preflow\Testing\ContextFactory::forTest(), function () use ($types, $registry): void {
            Contexts::narrow(static fn (RenderContext $ctx): RenderContext => $ctx->as(new Viewer(['api' => new TestUser()], 'api')));
            $all = $this->access($registry, ['*']);
            $some = $this->access($registry, ['job.view', 'job.edit']);

            $this->assertTrue(TypePermissions::allows($all, $types->get('faq'), 'view'), 'view is never restricted by the type');
            foreach (['create', 'edit', 'publish', 'delete'] as $verb) {
                $this->assertFalse(TypePermissions::allows($all, $types->get('faq'), $verb), "a read-only type: {$verb} false even with *");
            }
            $this->assertFalse(TypePermissions::allows($all, $types->get('inbox'), 'create'), 'not creatable');
            $this->assertTrue(TypePermissions::allows($all, $types->get('inbox'), 'edit'));
            $this->assertTrue(TypePermissions::allows($all, $types->get('inbox'), 'delete'));

            $this->assertTrue(TypePermissions::allows($some, $types->get('job'), 'view'));
            $this->assertTrue(TypePermissions::allows($some, $types->get('job'), 'edit'));
            $this->assertFalse(TypePermissions::allows($some, $types->get('job'), 'create'), 'a user without job.create');
            $this->assertFalse(TypePermissions::allows($some, $types->get('job'), 'publish'));
        });
    }

    public function test_declare_names_the_family_and_its_five_permissions_and_replaces_labels(): void
    {
        $registry = new PermissionRegistry();
        TypePermissions::declare($registry, 'job', 'Job');
        TypePermissions::declare($registry, 'job', 'Jobs');

        $this->assertSame(['job.*'], $registry->patterns(), 'the pattern once');
        $this->assertSame([
            'job.view' => ['label' => 'View', 'group' => 'Jobs'],
            'job.create' => ['label' => 'Create', 'group' => 'Jobs'],
            'job.edit' => ['label' => 'Edit', 'group' => 'Jobs'],
            'job.publish' => ['label' => 'Publish', 'group' => 'Jobs'],
            'job.delete' => ['label' => 'Delete', 'group' => 'Jobs'],
        ], $registry->all(), 'the last declaration names the group');
    }

    /** rbac on a site without Folio (its classes can't be loaded): every type's names are declared at boot. */
    #[RunInSeparateProcess]
    public function test_rbac_declares_type_permissions_without_folio(): void
    {
        $loader = require dirname(__DIR__, 3) . '/vendor/autoload.php';
        $loader->setPsr4('Preflow\\Folio\\', [sys_get_temp_dir() . '/no-such-folio']);
        $this->app([\Preflow\Rbac\RbacServiceProvider::class]);
        $this->model('job', ['label' => ['de' => 'Stellen', 'en' => 'Jobs']]);
        $this->model('faq', ['readonly' => true]);

        $registry = $this->boot()->container()->get(Access::class)->registry();

        $this->assertFalse(class_exists(\Preflow\Folio\FolioServiceProvider::class));
        $this->assertContains('job.*', $registry->patterns());
        $this->assertContains('faq.*', $registry->patterns());
        foreach (['view' => 'View', 'create' => 'Create', 'edit' => 'Edit', 'publish' => 'Publish', 'delete' => 'Delete'] as $verb => $label) {
            $this->assertSame(['label' => $label, 'group' => 'Jobs'], $registry->all()['job.' . $verb] ?? null, $verb);
            $this->assertSame(['label' => $label, 'group' => 'Faq'], $registry->all()['faq.' . $verb] ?? null, $verb);
        }
        $this->assertTrue($registry->isDeclared('rbac_role.edit'), 'rbac\'s own role type too');
        $this->assertTrue($registry->isDeclared(\Preflow\Rbac\RbacServiceProvider::VIEW_AS));
    }

    /** @return iterable<string, array{list<class-string>}> */
    public static function orders(): iterable
    {
        yield 'folio first' => [[\Preflow\Folio\FolioServiceProvider::class, \Preflow\Rbac\RbacServiceProvider::class]];
        yield 'rbac first' => [[\Preflow\Rbac\RbacServiceProvider::class, \Preflow\Folio\FolioServiceProvider::class]];
    }

    /**
     * Folio declares every type of its catalog with its catalog labels (its declare() replaces a
     * label); rbac only fills in what nobody declared, after every provider booted.
     *
     * @param list<class-string> $providers
     */
    #[RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\DataProvider('orders')]
    public function test_folio_labels_win_in_either_order(array $providers): void
    {
        $this->app($providers);
        $this->write('config/folio.php', "<?php\nreturn ['path' => '/folio'];\n");
        $this->model('page', ['label' => 'Pages']);
        $this->model('job', ['label' => ['de' => 'Stellen', 'en' => 'Jobs']]);

        $container = $this->boot()->container();
        $registry = $container->get(Access::class)->registry();
        $all = $registry->all();

        $expected = new PermissionRegistry();
        foreach ($container->get(\Preflow\Folio\Content\TypeCatalog::class)->all() as $listing) {
            TypePermissions::declare($expected, $listing->key, $listing->label);
        }
        $this->assertNotSame([], $expected->all());
        foreach ($expected->all() as $name => $entry) {
            $this->assertSame($entry, $all[$name] ?? null, "{$name}: Folio's catalog label");
        }
        $this->assertSame(['label' => 'Publish', 'group' => 'Pages'], $all['page.publish'] ?? null);
        $this->assertSame(['label' => 'View', 'group' => 'Jobs'], $all['job.view'] ?? null);
        $this->assertSame(['label' => 'Upload', 'group' => 'Assets'], $all['assets.upload'] ?? null, 'Folio\'s own names stay');
        $this->assertSame(1, count(array_keys($registry->patterns(), 'page.*', true)), 'the pattern once');
    }

    /** @param list<string> $grants */
    private function access(PermissionRegistry $registry, array $grants): Access
    {
        $access = new Access(new AuthManager([], 'api'), $registry, false, ['api' => 'members']);
        $access->setResolver(new class ($grants) implements PermissionResolver {
            /** @param list<string> $grants */
            public function __construct(private readonly array $grants) {}

            public function allows(Authenticatable $user, string $population, string $permission, ?object $subject): bool
            {
                return in_array('*', $this->grants, true) || in_array($permission, $this->grants, true);
            }
        });

        return $access;
    }

    /** @param list<class-string> $providers */
    private function app(array $providers): void
    {
        $this->dir = sys_get_temp_dir() . '/rbac_type_permissions_app_' . bin2hex(random_bytes(4));
        @mkdir($this->dir . '/storage/data', 0777, true);
        $this->errorLog = ini_set('error_log', $this->dir . '/error.log'); // stderr fails a separate-process test
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Type permissions', 'debug' => 0, 'engine' => 'twig', 'key' => 'rbac-type-permissions-key-0123456789'];\n");
        $this->write('config/providers.php', "<?php\nreturn [" . implode(', ', array_map(static fn (string $p): string => '\\' . $p . '::class', $providers)) . "];\n");
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'config', 'population' => 'staff']],
            'providers' => ['config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => []]],
            'session' => ['cookie' => 'rbac_type_permissions', 'path' => '/', 'secure' => false],
        ], true) . ";\n");
        $this->write('config/data.php', "<?php\nreturn " . var_export([
            'default' => 'json',
            'models_path' => $this->dir . '/config/models',
            'drivers' => ['json' => ['driver' => \Preflow\Data\Driver\JsonFileDriver::class, 'path' => $this->dir . '/storage/data']],
        ], true) . ";\n");
        $this->write('app/pages/index.twig', 'HOME');
    }

    private function boot(): Application
    {
        $app = Application::create($this->dir);
        $app->boot();

        return $app;
    }

    /** @param array<string, mixed> $options */
    private function model(string $key, array $options = []): void
    {
        $this->write('config/models/' . $key . '.json', (string) json_encode([
            'key' => $key, 'table' => $key, 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => ['title' => ['type' => 'string']],
        ] + $options));
    }

    private function write(string $rel, string $content): void
    {
        $path = $this->dir . '/' . $rel;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);
    }
}
