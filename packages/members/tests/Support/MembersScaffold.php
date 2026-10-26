<?php

declare(strict_types=1);

namespace Preflow\Members\Tests\Support;

use Nyholm\Psr7\Factory\Psr17Factory;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\DevTools\Command\MigrateCommand;
use Preflow\Testing\MysqlTestDatabase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A temp site with the members core and neither Folio nor rbac: staff from config/auth.php
 * (admin, the default guard), the members guard as the package README documents it, members on
 * SQLite (alias "members"), en/de, mail into the in-memory transport. Folio's members admin
 * tests build on it (packages/folio/tests/Members/Support/MembersScaffold adds Folio and rbac).
 */
trait MembersScaffold
{
    private string $dir = '';
    private string|false $errorLog = false;

    /** The one app of a session test (the admin session lives in-process between handle() calls). */
    private ?Application $flow = null;

    private const string ADMIN_EMAIL = 'admin@site.test';
    private const string ADMIN_PASSWORD = 'secret';

    /**
     * @param array<string, string> $aliases config/data.php aliases
     * @param list<string> $locales config/i18n.php available (the first is the default)
     * @param 'sqlite'|'mysql' $sql the SQL driver the aliases point to
     * @param list<string> $sessionPaths config/auth.php session.paths (where a session and its cookie exist)
     */
    private function scaffold(array $aliases = ['members' => 'sqlite', 'auth' => 'sqlite', 'throttle' => 'sqlite'], array $locales = ['en', 'de'], string $sql = 'sqlite', array $sessionPaths = ['/folio', '/members']): void
    {
        putenv('APP_DEBUG=1');
        $this->dir = sys_get_temp_dir() . '/members_' . bin2hex(random_bytes(4));
        @mkdir($this->dir . '/storage/data', 0777, true);
        $this->errorLog = ini_set('error_log', $this->dir . '/error.log'); // stderr fails a separate-process test
        // debug 0: the debug toolbar would list the SQL (password hashes included) on every page.
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Members', 'debug' => 0, 'engine' => 'twig', 'key' => 'members-app-test-key', 'url' => 'https://site.test'];\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Members\\MembersServiceProvider::class];\n");
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => [
                'session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'config', 'population' => 'staff'],
                'members' => [
                    'class' => \Preflow\Auth\SessionGuard::class,
                    'provider' => 'members',
                    'population' => 'members',
                    'remember' => ['cookie' => 'site_member', 'days' => 30, 'path' => '/'],
                ],
            ],
            'providers' => [
                'config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                    ['email' => self::ADMIN_EMAIL, 'password_hash' => password_hash(self::ADMIN_PASSWORD, PASSWORD_DEFAULT), 'roles' => ['admin']],
                ]],
                'members' => [
                    'class' => \Preflow\Auth\Record\RecordUserProvider::class,
                    'type' => 'member', 'email' => 'email', 'password' => 'password_hash', 'roles' => 'roles',
                ],
            ],
            'session' => ['cookie' => 'members_app_test', 'path' => '/', 'paths' => $sessionPaths, 'secure' => false],
        ], true) . ";\n");
        $drivers = ['json' => ['driver' => \Preflow\Data\Driver\JsonFileDriver::class, 'path' => $this->dir . '/storage/data']];
        $drivers[$sql] = $sql === 'mysql' ? MysqlTestDatabase::dataConfig() : ['path' => $this->dir . '/storage/site.sqlite'];
        $this->write('config/data.php', "<?php\nreturn " . var_export([
            'default' => 'json',
            'models_path' => $this->dir . '/config/models',
            'drivers' => $drivers,
            'aliases' => array_map(static fn (string $to): string => $to === 'sqlite' ? $sql : $to, $aliases),
        ], true) . ";\n");
        $this->write('config/i18n.php', "<?php\nreturn " . var_export([
            'default' => $locales[0], 'available' => $locales, 'fallback' => $locales[0], 'url_strategy' => 'prefix', 'cookie' => false,
        ], true) . ";\n");
        $this->write('config/notify.php', "<?php\nreturn ['transports' => ['mail' => ['driver' => 'memory']], 'channels' => ['email' => ['transport' => 'mail']]];\n");
        $this->write('config/models/page.json', (string) json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Pages',
            'fields' => ['title' => ['type' => 'string']],
        ]));
        $this->write('app/pages/index.twig', 'HOME');
    }

    private function removeScaffold(): void
    {
        putenv('APP_DEBUG');
        ini_set('error_log', (string) $this->errorLog);
        if ($this->dir === '' || !is_dir($this->dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    private function write(string $rel, string $content): void
    {
        $path = $this->dir . '/' . $rel;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);
    }

    /** A freshly booted app (one per request, like production). */
    private function app(): Application
    {
        $app = Application::create($this->dir);
        $app->boot();

        return $app;
    }

    private function container(): Container
    {
        return ($this->flow ??= $this->app())->container();
    }

    /**
     * Where a flow site's page layout goes, and the config/members.php layout naming it (null:
     * the default layout is that file).
     *
     * @return array{0: string, 1: ?string}
     */
    private function siteLayout(): array
    {
        return ['resources/members/_site.twig', '@members/_site.twig'];
    }

    /** As every deploy does: preflow migrate (a Folio site applies its extensions too). @return string the output */
    private function migrate(): string
    {
        [$code, $out] = $this->command(new MigrateCommand(fn () => $this->app()));
        $this->assertSame(0, $code, $out);

        return $out;
    }

    /** @param list<string> $args @return array{0: int, 1: string} exit code and output */
    private function command(object $command, array $args = []): array
    {
        ob_start();
        try {
            $code = $command->execute($args);
        } finally {
            $out = (string) ob_get_clean();
        }

        return [$code, $out];
    }

    /** @param array<string, mixed> $data */
    private function saveRecord(string $type, array $data): void
    {
        $dm = $this->container()->get(DataManager::class);
        $dm->saveType(DynamicRecord::fromArray($dm->getTypeRegistry()->get($type), $data), false);
    }

    /** @param array<string, string> $body */
    private function request(string $method, string $uri, array $body = []): ServerRequestInterface
    {
        $request = (new Psr17Factory())->createServerRequest($method, $uri, ['REMOTE_ADDR' => '203.0.113.7']);

        return $body === [] ? $request : $request->withParsedBody($body);
    }
}
