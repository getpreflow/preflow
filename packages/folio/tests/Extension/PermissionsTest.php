<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Extension;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Access\Access;
use Preflow\Core\Application;
use Preflow\Folio\Extension\ExtensionException;
use Preflow\Folio\Tests\Support\PublishingScaffold;

/**
 * Extensions declare permissions (#[DeclaresPermission], screens, record actions); those
 * of extensions that are not enabled are declared but disabled, so they are denied, not a 500.
 */
final class PermissionsTest extends TestCase
{
    use PublishingScaffold;

    private \Closure $autoload;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'config']],
            'providers' => ['config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => []]],
            'session' => ['cookie' => 'folio_perm_test', 'path' => '/', 'secure' => false],
        ], true) . ";\n");

        $root = $this->dir . '/app/Folio/';
        $this->autoload = static function (string $class) use ($root): void {
            if (str_starts_with($class, 'App\\Folio\\')) {
                $file = $root . str_replace('\\', '/', substr($class, strlen('App\\Folio\\'))) . '.php';
                if (is_file($file)) {
                    require $file;
                }
            }
        };
        spl_autoload_register($this->autoload);
    }

    protected function tearDown(): void
    {
        spl_autoload_unregister($this->autoload);
        $this->removeScaffold();
    }

    #[RunInSeparateProcess]
    public function test_extension_permission_declared_with_group(): void
    {
        $this->crm(enabled: true);

        $registry = $this->app()->container()->get(Access::class)->registry();

        $this->assertSame(['label' => 'Export leads', 'group' => 'CRM'], $registry->all()['crm.export']);
        $this->assertSame(['label' => 'Reports', 'group' => 'CRM'], $registry->all()['crm.report']); // screen
        $this->assertSame(['label' => 'Qualify', 'group' => 'CRM'], $registry->all()['crm.qualify']); // record action
        $this->assertNull($registry->disabledReason('crm.export'));
        $this->assertNull($registry->disabledReason('lead.view'));
        $this->assertTrue($registry->isDeclared('lead.edit')); // the enabled model's standard permissions
    }

    #[RunInSeparateProcess]
    public function test_permission_outside_extension_namespace_throws(): void
    {
        $this->crm(enabled: true, extra: "#[\\Preflow\\Folio\\Extension\\Attributes\\DeclaresPermission('other.export', 'Nope')]");

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessage('"other.export"');
        $this->app();
    }

    #[RunInSeparateProcess]
    public function test_permission_equal_to_a_builtin_throws(): void
    {
        $this->crm(enabled: true, screenPermission: 'post.view');

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessage('"post.view"');
        $this->app();
    }

    #[RunInSeparateProcess]
    public function test_disabled_extension_permissions_denied(): void
    {
        $this->crm(enabled: false);

        $log = (string) tempnam(sys_get_temp_dir(), 'perm');
        ini_set('error_log', $log);
        $app = $this->app(); // no throw, even in debug
        $access = $app->container()->get(Access::class);
        $registry = $access->registry();

        foreach (['crm.export', 'crm.report', 'crm.qualify', 'lead.view', 'lead.publish'] as $name) {
            $this->assertTrue($registry->isDeclared($name), $name);
            $this->assertSame('extension crm', $registry->disabledReason($name), $name);
            $this->assertFalse($access->can($name), $name);
        }
        $this->assertSame('CRM', $registry->all()['crm.export']['group']);
        $this->assertSame(1, substr_count((string) file_get_contents($log), 'permission crm.export belongs to a disabled extension crm'));
        ini_restore('error_log');
        @unlink($log);
        $this->assertNull($registry->disabledReason('post.view'));
    }

    #[RunInSeparateProcess]
    public function test_disabled_extension_model_shadowing_site_type_throws(): void
    {
        $this->crm(enabled: false);
        $this->write('app/Folio/Crm/models/post.json', '{"key": "post", "label": "Posts", "table": "post", "storage": "json", "id_field": "uuid", "fields": {"title": {"type": "string"}}}');

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessage('"post"');
        $this->app();
    }

    /**
     * An extension "crm": a #[DeclaresPermission], a screen, a "lead" model and a type
     * customization of "post" with a record action.
     */
    private function crm(bool $enabled, string $extra = '', string $screenPermission = 'crm.report'): void
    {
        $this->write('app/Folio/Crm/Extension.php', <<<PHP
            <?php
            namespace App\\Folio\\Crm;
            #[\\Preflow\\Folio\\Extension\\Attributes\\FolioExtension(name: 'crm', label: 'CRM')]
            #[\\Preflow\\Folio\\Extension\\Attributes\\DeclaresPermission('crm.export', 'Export leads')]
            {$extra}
            final class Extension extends \\Preflow\\Folio\\Extension\\Extension {}
            PHP);
        $this->write('app/Folio/Crm/models/lead.json', '{"key": "lead", "label": "Leads", "table": "lead", "storage": "json", "id_field": "uuid", "fields": {"email": {"type": "string"}}}');
        $this->write('app/Folio/Crm/Screens/Reports.php', <<<PHP
            <?php
            namespace App\\Folio\\Crm\\Screens;
            #[\\Preflow\\Folio\\Extension\\Attributes\\FolioScreen('reports', 'Reports', permission: '{$screenPermission}')]
            final class Reports
            {
                #[\\Preflow\\Routing\\Attributes\\Get('/')]
                public function index(): string { return 'reports'; }
            }
            PHP);
        $this->write('app/Folio/Crm/Types/PostActions.php', <<<'PHP'
            <?php
            namespace App\Folio\Crm\Types;
            #[\Preflow\Folio\Extension\Attributes\FolioType('post')]
            final class PostActions
            {
                #[\Preflow\Folio\Extension\RecordAction('qualify', 'Qualify', 'crm.qualify')]
                public function qualify(): void {}
            }
            PHP);
        if ($enabled) {
            $this->write('config/folio/extensions.json', '{"enabled": {"crm": {"disabled_parts": []}}}');
        }
    }
}
