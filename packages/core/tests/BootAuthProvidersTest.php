<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

require_once __DIR__ . '/Fixtures/BrokenUserProvider.php';
require_once __DIR__ . '/Fixtures/ConfigEntryUserProvider.php';
require_once __DIR__ . '/Fixtures/LateServiceUserProvider.php';
require_once __DIR__ . '/Fixtures/MissingTypeUserProvider.php';

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Tokens\Tokens;
use Preflow\Auth\UserProviderInterface;
use Preflow\Core\Application;
use Preflow\Core\Tests\Fixtures\BrokenUserProvider;
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
    public function test_chain_provider_tries_named_providers_in_order(): void
    {
        file_put_contents($this->dir . '/config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'chain']],
            'providers' => [
                'first' => ['class' => ConfigEntryUserProvider::class, 'ids' => ['m1']],
                'config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [['email' => 'a@b.c', 'password_hash' => 'x', 'roles' => []]]],
                'chain' => ['class' => \Preflow\Auth\ChainUserProvider::class, 'providers' => ['first', 'config']],
            ],
            'session' => ['cookie' => 'providers_test', 'secure' => false],
        ], true) . ";\n");
        $app = Application::create($this->dir);
        $app->boot();
        $provider = $app->container()->get(UserProviderInterface::class);

        $this->assertInstanceOf(\Preflow\Auth\ChainUserProvider::class, $provider);
        $this->assertSame('m1', $provider->findById('m1')?->getAuthId());
        $this->assertSame('a@b.c', $provider->findById('a@b.c')?->getAuthId());
        $this->assertNull($provider->findById('nobody'));
    }

    #[RunInSeparateProcess]
    public function test_chain_naming_an_unknown_provider_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('"ghost"');
        $this->boot(['class' => \Preflow\Auth\ChainUserProvider::class, 'providers' => ['ghost']]);
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

    #[RunInSeparateProcess]
    public function test_provider_failing_for_another_reason_still_throws_at_boot(): void
    {
        file_put_contents($this->dir . '/config/providers.php', "<?php\nreturn [" . LateServiceProvider::class . "::class];\n");
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('could not connect');
        $this->boot(['class' => BrokenUserProvider::class]);
    }

    #[RunInSeparateProcess]
    public function test_a_record_user_provider_is_built_from_config_without_folio(): void
    {
        @mkdir($this->dir . '/models', 0777, true);
        @mkdir($this->dir . '/data', 0777, true);
        file_put_contents($this->dir . '/models/member.json', (string) json_encode([
            'key' => 'member', 'table' => 'member', 'id_field' => 'uuid',
            'fields' => ['email' => ['type' => 'string'], 'password_hash' => ['type' => 'string'], 'roles' => ['type' => 'list']],
        ]));
        $app = $this->boot(
            ['class' => \Preflow\Auth\Record\RecordUserProvider::class, 'type' => 'member', 'email' => 'email', 'password' => 'password_hash', 'roles' => 'roles'],
            var_export(['default' => 'json', 'models_path' => $this->dir . '/models', 'drivers' => ['json' => ['driver' => \Preflow\Data\Driver\JsonFileDriver::class, 'path' => $this->dir . '/data']]], true),
        );
        $dm = $app->container()->get(\Preflow\Data\DataManager::class);
        $dm->saveType(\Preflow\Data\DynamicRecord::fromArray($dm->getTypeRegistry()->get('member'), ['uuid' => 'm1', 'email' => 'ada@example.com', 'password_hash' => 'x', 'roles' => ['member']]), false);

        $provider = $app->container()->get(UserProviderInterface::class);

        $this->assertInstanceOf(\Preflow\Auth\Record\RecordUserProvider::class, $provider->resolve());
        $this->assertSame(['member'], $provider->findById('m1')?->getRoles());
    }
}
