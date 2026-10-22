<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Users;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\AuthManager;
use Preflow\Auth\SessionGuard;
use Preflow\Core\Application;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Storage\TableSync;
use Preflow\Folio\Users\RecordUser;
use Preflow\Folio\Users\RecordUserProvider;
use Preflow\Testing\ArraySession;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidatorFactory;

final class RecordUserProviderTest extends TestCase
{
    private string $dir;
    private DataManager $dm;
    private TypeCatalog $catalog;

    private const MEMBER = [
        'key' => 'member', 'table' => 'member', 'storage' => 'sqlite', 'id_field' => 'uuid', 'users' => 'members',
        'fields' => ['email' => ['type' => 'string'], 'password_hash' => ['type' => 'string'], 'roles' => ['type' => 'string']],
    ];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/recuser_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0777, true);
        file_put_contents($this->dir . '/models/member.json', json_encode(self::MEMBER));
        file_put_contents($this->dir . '/models/page.json', json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'sqlite', 'id_field' => 'uuid', 'fields' => ['title' => ['type' => 'string']],
        ]));
        $this->catalog = new TypeCatalog($this->dir . '/models');
        $registry = new TypeRegistry($this->dir . '/models');
        $this->dm = new DataManager(
            drivers: ['sqlite' => new SqliteDriver(new \PDO('sqlite:' . $this->dir . '/a.sqlite'))],
            defaultDriver: 'sqlite',
            typeRegistry: $registry,
            validatorFactory: new ValidatorFactory(new RuleFactory()),
        );
        (new TableSync($this->catalog, $registry, $this->dm))->sync();
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->dir);
    }

    private function rmTree(string $dir): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    private function provider(array $config = ['type' => 'member']): RecordUserProvider
    {
        return new RecordUserProvider($this->dm, $this->catalog, $config);
    }

    private function member(string $id, string $email, string $roles = '[]'): void
    {
        $this->dm->saveType(new DynamicRecord($this->dm->getTypeRegistry()->get('member'), [
            'uuid' => $id, 'email' => $email, 'password_hash' => 'hash-' . $id, 'roles' => $roles,
        ]), false);
    }

    public function test_find_by_id(): void
    {
        $this->member('m1', 'ada@example.com');

        $user = $this->provider()->findById('m1');

        $this->assertInstanceOf(RecordUser::class, $user);
        $this->assertSame('m1', $user->getAuthId());
        $this->assertSame('hash-m1', $user->getPasswordHash());
        $this->assertSame('ada@example.com', $user->record()->get('email'));
        $this->assertNull($this->provider()->findById('nope'));
    }

    public function test_find_by_credentials_is_case_insensitive(): void
    {
        $this->member('m1', 'ada@example.com');

        $this->assertSame('m1', $this->provider()->findByCredentials(['email' => '  Ada@Example.com '])?->getAuthId());
        $this->assertNull($this->provider()->findByCredentials(['email' => 'bob@example.com']));
        $this->assertNull($this->provider()->findByCredentials([]));
    }

    public function test_find_by_id_does_not_cross_types(): void
    {
        $this->member('m1', 'ada@example.com');
        $this->dm->saveType(new DynamicRecord($this->dm->getTypeRegistry()->get('page'), ['uuid' => 'p1', 'title' => 'Hi']), false);

        $this->assertNull($this->provider()->findById('p1'));
    }

    public function test_lookalike_or_duplicate_emails_never_match_another_account(): void
    {
        $this->member('m1', 'ada@example.com');
        $this->member('m2', 'ADA@example.com');

        $this->assertSame('m1', $this->provider()->findByCredentials(['email' => 'ada@example.com'])?->getAuthId());

        $this->member('m3', 'ada@example.com');

        $this->assertNull($this->provider()->findByCredentials(['email' => 'ada@example.com']));
    }

    public function test_roles_from_json_string_on_sqlite(): void
    {
        $this->member('m1', 'ada@example.com', '["gold"," silver ","gold"]');

        $user = $this->provider()->findById('m1');

        $this->assertSame(['gold', 'silver'], $user->getRoles());
        $this->assertTrue($user->hasRole('gold'));
        $this->assertFalse($user->hasRole('bronze'));
    }

    public function test_roles_from_array_and_csv(): void
    {
        $type = $this->dm->getTypeRegistry()->get('member');

        $this->assertSame(['a', 'b'], (new RecordUser(new DynamicRecord($type, ['roles' => ['a', 'b']])))->getRoles());
        $this->assertSame(['a', 'b'], (new RecordUser(new DynamicRecord($type, ['roles' => 'a, b'])))->getRoles());
        $this->assertSame([], (new RecordUser(new DynamicRecord($type, [])))->getRoles());
    }

    public function test_non_user_type_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a user type');
        $this->provider(['type' => 'page']);
    }

    public function test_unknown_type_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not exist');
        $this->provider(['type' => 'ghost']);
    }

    #[RunInSeparateProcess]
    public function test_login_through_session_guard(): void
    {
        $this->rmTree($this->dir);
        $dir = $this->dir = sys_get_temp_dir() . '/recuser_app_' . bin2hex(random_bytes(4));
        $w = function (string $rel, string $content) use ($dir): void {
            @mkdir(dirname($dir . '/' . $rel), 0777, true);
            file_put_contents($dir . '/' . $rel, $content);
        };
        $w('config/app.php', "<?php\nreturn ['name' => 'T', 'debug' => 0, 'engine' => 'twig', 'key' => 'record-user-test-key-0123456789'];\n");
        $w('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class];\n");
        $w('config/folio.php', "<?php\nreturn ['path' => '/folio'];\n");
        $w('config/data.php', "<?php\nreturn " . var_export([
            'default' => 'sqlite',
            'models_path' => $dir . '/config/models',
            'drivers' => ['sqlite' => ['path' => $dir . '/app.sqlite']],
        ], true) . ";\n");
        $w('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'member',
            'guards' => ['member' => ['class' => SessionGuard::class, 'provider' => 'members', 'population' => 'members']],
            'providers' => ['members' => ['class' => RecordUserProvider::class, 'type' => 'member']],
            'session' => ['cookie' => 'record_user_test', 'path' => '/', 'secure' => false],
        ], true) . ";\n");
        $w('config/models/member.json', (string) json_encode(self::MEMBER));
        $w('app/pages/index.twig', 'HOME');

        $app = Application::create($dir);
        $app->boot();
        $c = $app->container();
        (new TableSync($c->get(TypeCatalog::class), $c->get(DataManager::class)->getTypeRegistry(), $c->get(DataManager::class)))->sync();
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $c->get(DataManager::class)->saveType(new DynamicRecord($c->get(DataManager::class)->getTypeRegistry()->get('member'), [
            'uuid' => 'm1', 'email' => 'ada@example.com', 'password_hash' => $hash, 'roles' => '["gold"]',
        ]), false);

        $guard = $c->get(AuthManager::class)->guard('member');
        $request = (new Psr17Factory())->createServerRequest('GET', '/')
            ->withAttribute(SessionInterface::class, new ArraySession());

        $this->assertFalse($guard->validate(['email' => 'ada@example.com', 'password' => 'wrong']));
        $this->assertTrue($guard->validate(['email' => 'Ada@Example.com', 'password' => 'secret']));
        $this->assertNull($guard->user($request));

        $user = $c->get(\Preflow\Auth\UserProviderInterface::class)->findByCredentials(['email' => 'ada@example.com']);
        $guard->login($user, $request);

        $current = $guard->user($request);
        $this->assertInstanceOf(RecordUser::class, $current);
        $this->assertSame('m1', $current->getAuthId());
        $this->assertTrue($current->hasRole('gold'));
    }
}
