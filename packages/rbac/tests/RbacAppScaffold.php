<?php

declare(strict_types=1);

namespace Preflow\Rbac\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Preflow\Core\Application;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Extension\ExtensionState;
use Preflow\Rbac\Extension;
use Psr\Http\Message\ResponseInterface;

/**
 * A temp app with Folio, auth (staff from config/auth.php: admin, editor, keeper) and the
 * rbac extension enabled (a fake vendor/composer/installed.json lists the package), with
 * a "page" type and a "member" user type (population members).
 */
trait RbacAppScaffold
{
    private string $dir;
    private ?Application $session = null;
    private string $token = '';
    private string|false $errorLog = false;

    private function scaffold(): void
    {
        putenv('APP_DEBUG=1');
        $this->dir = sys_get_temp_dir() . '/rbac_app_' . bin2hex(random_bytes(4));
        @mkdir($this->dir, 0777, true);
        $this->errorLog = ini_set('error_log', $this->dir . '/error.log'); // stderr fails a separate-process test
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Rbac', 'debug' => 1, 'engine' => 'twig', 'key' => 'rbac-app-test-key'];\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Rbac\\RbacServiceProvider::class];\n");
        $this->write('config/folio.php', "<?php\nreturn ['path' => '/folio'];\n");
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'config', 'population' => 'staff']],
            'providers' => ['config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                ['email' => 'admin@site.test', 'password_hash' => $hash, 'roles' => ['admin']],
                ['email' => 'editor@site.test', 'password_hash' => $hash, 'roles' => ['editor']],
                ['email' => 'keeper@site.test', 'password_hash' => $hash, 'roles' => ['keeper']],
            ]]],
            'session' => ['cookie' => 'rbac_app_test', 'path' => '/', 'secure' => false],
        ], true) . ";\n");
        $this->write('config/data.php', "<?php\nreturn " . var_export([
            'default' => 'json',
            'models_path' => $this->dir . '/config/models',
            'drivers' => ['json' => ['driver' => \Preflow\Data\Driver\JsonFileDriver::class, 'path' => $this->dir . '/storage/data']],
        ], true) . ";\n");
        $this->write('config/models/page.json', (string) json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Pages',
            'fields' => ['title' => ['type' => 'string']],
        ]));
        $this->write('config/models/member.json', (string) json_encode([
            'key' => 'member', 'table' => 'member', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Members', 'users' => 'members',
            'fields' => [
                'email' => ['type' => 'string'],
                'roles' => ['type' => 'rbac_roles', 'label' => 'Roles'],
                'staff_roles' => ['type' => 'rbac_roles', 'label' => 'Staff roles', 'population' => 'staff'],
            ],
        ]));
        $this->write('vendor/composer/installed.json', (string) json_encode(['packages' => [
            ['name' => 'preflow/rbac', 'extra' => ['folio' => ['extension' => 'Preflow\\Rbac\\Extension']]],
        ]]));
        $this->write('config/folio/extensions.json', '{"enabled": {"rbac": {"disabled_parts": []}}}');
        $this->write('app/pages/index.twig', 'HOME');
        @mkdir($this->dir . '/storage/data', 0777, true);
    }

    private function removeScaffold(): void
    {
        putenv('APP_DEBUG');
        ini_set('error_log', (string) $this->errorLog);
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

    /** @return list<string> lines written to the error log */
    private function errorLog(): array
    {
        return array_values(array_filter(explode("\n", (string) @file_get_contents($this->dir . '/error.log'))));
    }

    private function app(): Application
    {
        $app = Application::create($this->dir);
        $app->boot();

        return $app;
    }

    /** The test's one app (a second app booted after a session started warns). */
    private function container(): \Preflow\Core\Container\Container
    {
        return ($this->session ??= $this->app())->container();
    }

    /** As folio:extensions:apply does: install() of rbac, then the installed version. */
    private function install(): void
    {
        $c = $this->container();
        $c->get(Extension::class)->install($c);
        $c->get(ExtensionState::class)->markInstalled('rbac', '1.0.0');
    }

    /** @param list<string> $permissions */
    private function role(string $id, string $population, string $name, array $permissions): void
    {
        $dm = $this->container()->get(DataManager::class);
        $dm->saveType(DynamicRecord::fromArray($dm->getTypeRegistry()->get('rbac_role'), [
            'uuid' => $id, 'name' => $name, 'label' => ucfirst($name), 'population' => $population, 'permissions' => $permissions,
        ]), false);
    }

    /** @return array<string, DynamicRecord> */
    private function roles(): array
    {
        return $this->container()->get(DataManager::class)->queryType('rbac_role')->get()->items();
    }

    private function stored(string $type, string $id): ?DynamicRecord
    {
        return $this->container()->get(DataManager::class)->findType($type, $id);
    }

    private function as(string $user): void
    {
        $this->session ??= $this->app();
        $f = new Psr17Factory();
        $page = (string) $this->session->handle($f->createServerRequest('GET', '/folio/login'))->getBody();
        preg_match('/name="_csrf_token" value="([^"]+)"/', $page, $m);
        $this->token = $m[1] ?? '';
        $login = $this->session->handle($f->createServerRequest('POST', '/folio/login')->withParsedBody([
            '_csrf_token' => $this->token, 'email' => "{$user}@site.test", 'password' => 'secret',
        ]));
        $this->assertSame(302, $login->getStatusCode());
    }

    /** @param array<string, mixed> $body */
    private function send(string $method, string $uri, array $body = []): ResponseInterface
    {
        $req = (new Psr17Factory())->createServerRequest($method, $uri);
        if ($method === 'POST') {
            $req = $req->withParsedBody(['_csrf_token' => $this->token] + $body);
        }

        return $this->session->handle($req);
    }
}
