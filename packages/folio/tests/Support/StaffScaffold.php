<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Support;

use Nyholm\Psr7\Factory\Psr17Factory;
use Preflow\Core\Application;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\DevTools\Command\MigrateCommand;
use Preflow\Notify\Email;
use Preflow\Notify\TransportFactory;
use Psr\Http\Message\ResponseInterface;

/**
 * A temp site whose Folio users are records of a "staff" user type (SQLite or MySQL), chained in front of a
 * config user, as a site with staff accounts configures it: one app handles a whole flow (the
 * native session lives in $_SESSION, so the tests run in a separate process). Mail goes to the
 * in-memory transport.
 */
trait StaffScaffold
{
    private string $dir = '';
    private string|false $errorLog = false;
    private ?Application $flow = null;
    private string $remoteAddr = '203.0.113.7';

    private const string CONFIG_EMAIL = 'config@site.test';
    private const string PASSWORD = 'correct horse battery';

    /**
     * @param array<string, mixed> $guard  more of the session guard's config (remember)
     * @param array<string, mixed> $folio  config/folio.php
     * @param string|null          $locale config/i18n.php default (null: no i18n config)
     * @param 'sqlite'|'mysql'     $sql    where staff, tokens, remember rows and throttle live
     */
    private function staffSite(array $guard = ['remember' => ['cookie' => 'folio_remember', 'days' => 30, 'path' => '/folio']], array $folio = [], ?string $locale = null, string $sql = 'sqlite'): void
    {
        putenv('APP_DEBUG=1');
        $this->dir = sys_get_temp_dir() . '/folio_staff_' . bin2hex(random_bytes(4));
        @mkdir($this->dir . '/storage/data', 0777, true);
        $this->errorLog = ini_set('error_log', $this->dir . '/error.log');
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Staff Site', 'debug' => 0, 'engine' => 'twig', 'key' => 'folio-staff-test-key', 'url' => 'https://site.test'];\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class];\n");
        $this->write('config/folio.php', "<?php\nreturn " . var_export(['path' => '/folio'] + $folio, true) . ";\n");
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'users'] + $guard],
            'providers' => [
                'staff' => ['class' => \Preflow\Folio\Users\RecordUserProvider::class, 'type' => 'staff', 'active' => 'active'],
                'config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                    ['email' => self::CONFIG_EMAIL, 'password_hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT), 'roles' => ['admin']],
                ]],
                'users' => ['class' => \Preflow\Auth\ChainUserProvider::class, 'providers' => ['staff', 'config']],
            ],
            'session' => ['cookie' => 'folio_staff_test', 'path' => '/', 'paths' => ['/folio'], 'secure' => false],
        ], true) . ";\n");
        $this->write('config/data.php', "<?php\nreturn " . var_export([
            'default' => 'json',
            'models_path' => $this->dir . '/config/models',
            'drivers' => [
                'json' => ['driver' => \Preflow\Data\Driver\JsonFileDriver::class, 'path' => $this->dir . '/storage/data'],
                $sql => $sql === 'mysql' ? \Preflow\Testing\MysqlTestDatabase::dataConfig() : ['path' => $this->dir . '/storage/site.sqlite'],
            ],
            'aliases' => ['auth' => $sql, 'throttle' => $sql, 'staffdb' => $sql],
        ], true) . ";\n");
        if ($locale !== null) {
            $this->write('config/i18n.php', "<?php\nreturn " . var_export([
                'default' => $locale, 'available' => [$locale], 'fallback' => $locale, 'url_strategy' => 'prefix', 'cookie' => false,
            ], true) . ";\n");
        }
        $this->write('config/notify.php', "<?php\nreturn ['transports' => ['mail' => ['driver' => 'memory']], 'channels' => ['email' => ['transport' => 'mail', 'from' => 'folio@site.test']]];\n");
        $this->write('config/models/page.json', (string) json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Pages',
            'fields' => ['title' => ['type' => 'string']],
        ]));
        $this->write('config/models/staff.json', (string) json_encode([
            'key' => 'staff', 'table' => 'staff', 'storage' => 'staffdb', 'id_field' => 'uuid', 'label' => 'Staff', 'users' => 'staff',
            'fields' => [
                'email' => ['type' => 'string'], 'name' => ['type' => 'string'], 'password_hash' => ['type' => 'string', 'hidden' => true],
                'roles' => ['type' => 'string'], 'active' => ['type' => 'boolean', 'default' => true],
                'auth_epoch' => ['type' => 'string', 'default' => '0'], 'last_login_at' => ['type' => 'date', 'time' => true],
            ],
        ]));
        $this->write('app/pages/index.twig', 'HOME');

        ob_start();
        try {
            $code = (new MigrateCommand(fn () => $this->app()))->execute([]);
        } finally {
            $out = (string) ob_get_clean();
        }
        $this->assertSame(0, $code, $out);
        $this->flow = $this->app();
    }

    private function removeSite(): void
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

    private function app(): Application
    {
        $app = Application::create($this->dir);
        $app->boot();

        return $app;
    }

    /** @param array<string, string> $body @param array<string, string> $cookies */
    private function send(string $method, string $uri, array $body = [], array $cookies = []): ResponseInterface
    {
        $request = (new Psr17Factory())->createServerRequest($method, $uri, ['REMOTE_ADDR' => $this->remoteAddr]);
        if ($cookies !== []) {
            $request = $request->withCookieParams($cookies);
        }

        return $this->flow->handle($body === [] ? $request : $request->withParsedBody($body));
    }

    /** POST with the session's CSRF token (a GET of the login page starts the session first). @param array<string, string> $body */
    private function post(string $uri, array $body): ResponseInterface
    {
        if (!isset($_SESSION['_csrf_token'])) {
            $this->send('GET', '/folio/login');
        }

        return $this->send('POST', $uri, $body + ['_csrf_token' => (string) ($_SESSION['_csrf_token'] ?? '')]);
    }

    private function html(string $uri): string
    {
        return (string) $this->send('GET', $uri)->getBody();
    }

    private function dm(): DataManager
    {
        return $this->flow->container()->get(DataManager::class);
    }

    /** @param array<string, mixed> $data */
    private function makeStaff(string $email = 'ada@site.test', bool $active = true, ?string $password = self::PASSWORD, array $data = []): DynamicRecord
    {
        $dm = $this->dm();
        $record = DynamicRecord::fromArray($dm->getTypeRegistry()->get('staff'), $data + [
            'uuid' => bin2hex(random_bytes(8)),
            'email' => $email,
            'name' => 'Ada Lovelace',
            'password_hash' => $password === null ? '' : password_hash($password, PASSWORD_DEFAULT),
            'roles' => 'admin',
            'active' => $active,
            'auth_epoch' => '0',
        ]);
        $dm->saveType($record, false);

        return $record;
    }

    private function staff(string $email): DynamicRecord
    {
        $record = $this->dm()->queryType('staff')->where('email', $email)->first();

        return $record instanceof DynamicRecord ? $record : $this->fail('No staff ' . $email);
    }

    /** @return list<Email> */
    private function mails(): array
    {
        return array_values(array_map(
            static fn ($s) => $s->message,
            $this->flow->container()->get(TransportFactory::class)->get('mail')->sent(),
        ));
    }

    private function linkToken(int $mail = 0): string
    {
        preg_match('~https://site\.test/folio/password/([A-Za-z0-9_-]+)~', $this->mails()[$mail]->text ?? '', $m);

        return $m[1] ?? $this->fail("No set-password link in mail {$mail}");
    }

    private function cookie(ResponseInterface $response, string $name): ?string
    {
        foreach ($response->getHeader('Set-Cookie') as $line) {
            if (str_starts_with($line, $name . '=')) {
                return $line;
            }
        }

        return null;
    }

    /** Switches the browser: the session of another one (an empty one for a new browser). @return array<string, mixed> the one left */
    private function useSession(array $session): array
    {
        $left = $_SESSION ?? [];
        $_SESSION = $session + ['_flash' => ['current' => [], 'previous' => []]];

        return $left;
    }

    /** The page without the per-request parts (CSRF token, nonce, asset version). */
    private function stable(string $html): string
    {
        return (string) preg_replace(
            ['~name="_csrf_token" value="[^"]*"~', '~nonce="[^"]*"~', '~\?v=[^"]*~'],
            ['name="_csrf_token" value=""', 'nonce=""', '?v='],
            $html,
        );
    }
}
