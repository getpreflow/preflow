<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

require_once __DIR__ . '/Fixtures/ConfigEntryUserProvider.php';
require_once __DIR__ . '/Fixtures/LateServiceUserProvider.php';
require_once __DIR__ . '/Fixtures/MissingTypeUserProvider.php';

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Tokens\Tokens;
use Preflow\Auth\UserProviderInterface;
use Preflow\Core\Application;
use Preflow\Core\Tests\Fixtures\ConfigEntryUserProvider;
use Preflow\Core\Tests\Fixtures\LateServiceProvider;
use Preflow\Core\Tests\Fixtures\LateServiceUserProvider;
use Preflow\Core\Tests\Fixtures\MissingTypeUserProvider;
use Preflow\Data\Migration\MigrationRegistry;

/** User providers from config/auth.php (any class), and the auth services core binds. */
final class BootAuthProvidersTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/authproviders_' . bin2hex(random_bytes(4));
        @mkdir($this->dir . '/config', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'providers-test-key-0123456789abcdef'];\n");
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    /** @param array<string, mixed> $provider */
    private function boot(array $provider, ?string $dataConfig = null): Application
    {
        file_put_contents($this->dir . '/config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'users']],
            'providers' => ['users' => $provider],
            'session' => ['cookie' => 'providers_test', 'secure' => false],
        ], true) . ";\n");
        if ($dataConfig !== null) {
            file_put_contents($this->dir . '/config/data.php', "<?php\nreturn " . $dataConfig . ";\n");
        }
        $app = Application::create($this->dir);
        $app->boot();

        return $app;
    }

    #[RunInSeparateProcess]
    public function test_custom_provider_class_built_from_config(): void
    {
        $entry = ['class' => ConfigEntryUserProvider::class, 'ids' => ['m1']];
        $app = $this->boot($entry);
        $provider = $app->container()->get(UserProviderInterface::class);

        $this->assertInstanceOf(\Preflow\Auth\LazyUserProvider::class, $provider);
        $this->assertInstanceOf(ConfigEntryUserProvider::class, $provider->resolve());
        $this->assertSame($entry, $provider->resolve()->config);
        $this->assertSame('m1', $provider->findById('m1')?->getAuthId());
    }

    #[RunInSeparateProcess]
    public function test_provider_class_not_a_provider_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Provider "users"');
        $this->boot(['class' => \ArrayObject::class]);
    }

    #[RunInSeparateProcess]
    public function test_tokens_bound_on_auth_storage_with_migration(): void
    {
        $app = $this->boot(
            ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => []],
            "['default' => 'json', 'drivers' => ['sqlite' => ['path' => '{$this->dir}/app.sqlite'], 'json' => ['path' => '{$this->dir}/json']], 'aliases' => ['auth' => 'sqlite']]",
        );
        $tokens = $app->container()->get(Tokens::class);
        $this->assertSame('auth', (new \ReflectionProperty(Tokens::class, 'storage'))->getValue($tokens));

        $paths = $app->container()->get(MigrationRegistry::class)->paths();
        $this->assertContains(realpath(dirname(__DIR__, 2) . '/auth/migrations'), array_map(realpath(...), $paths));
    }

    #[RunInSeparateProcess]
    public function test_tokens_fall_back_to_default_storage(): void
    {
        $app = $this->boot(
            ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => []],
            "['default' => 'json', 'drivers' => ['json' => ['path' => '{$this->dir}/json']]]",
        );
        $tokens = $app->container()->get(Tokens::class);
        $this->assertSame('default', (new \ReflectionProperty(Tokens::class, 'storage'))->getValue($tokens));
    }

    #[RunInSeparateProcess]
    public function test_provider_needing_late_services_is_resolved_after_providers(): void
    {
        file_put_contents($this->dir . '/config/providers.php', "<?php\nreturn [" . LateServiceProvider::class . "::class];\n");
        $app = $this->boot(['class' => LateServiceUserProvider::class]);

        $provider = $app->container()->get(UserProviderInterface::class);
        $this->assertSame('late', $provider->resolve()->marker->value);
    }

    #[RunInSeparateProcess]
    public function test_provider_for_a_missing_user_type_resolves_nobody(): void
    {
        file_put_contents($this->dir . '/config/providers.php', "<?php\nreturn [" . LateServiceProvider::class . "::class];\n");
        $app = $this->boot(['class' => MissingTypeUserProvider::class]);

        $provider = $app->container()->get(UserProviderInterface::class);
        $this->assertNull($provider->findById('m1'));
        $this->assertNull($provider->findByCredentials(['email' => 'a@b.test']));
    }
}
