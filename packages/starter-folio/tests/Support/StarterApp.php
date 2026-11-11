<?php

declare(strict_types=1);

namespace Tests\Support;

use Nyholm\Psr7\Factory\Psr17Factory;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\DevTools\Console;
use Preflow\Testing\Fast\SetupSnapshots;
use Preflow\Testing\Fast\TestDataPaths;
use Preflow\Testing\TestResponse;
use Preflow\Testing\WebApplication;
use Psr\Http\Message\ResponseInterface;

/**
 * Boots the real app on a copy of the project, with its own SQLite file, so tests never touch
 * storage/data. Migrate and the demo seed run once per state (SetupSnapshots): a later test
 * that starts from the same state gets their result restored instead of running them again.
 */
trait StarterApp
{
    /**
     * Never copied: the code runs from the project, the copy only holds config, templates and data.
     * vendor/ is linked instead (see projectVendor()).
     */
    private const array NOT_COPIED = ['vendor', 'storage', 'tests', '.env', '.git'];

    /** Left out of an API-only copy (the README says to delete the same three paths). */
    private const array SITE_PATHS = ['app/Components', 'app/pages', 'app/Controllers/Site'];

    private string $starterRoot = '';

    private ?SetupSnapshots $snapshots = null;

    private ?Application $starterApp = null;

    /** Whether the last bootStarter() got its migrated and seeded set-up from a snapshot. */
    private bool $setUpRestored = false;

    /** Boot as the web serves (the page cache serves and stores, and verifies what it stores). */
    private bool $asWeb = false;

    private function bootStarter(bool $apiOnly = false, bool $debug = true): void
    {
        $project = dirname(__DIR__, 2);
        $this->starterRoot = sys_get_temp_dir() . '/starter_' . bin2hex(random_bytes(4));
        mkdir($this->starterRoot . '/storage/cache', 0777, true);
        mkdir($this->starterRoot . '/storage/logs', 0777, true);

        foreach (scandir($project) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || in_array($entry, self::NOT_COPIED, true)) {
                continue;
            }
            exec('cp -R ' . escapeshellarg($project . '/' . $entry) . ' ' . escapeshellarg($this->starterRoot . '/' . $entry));
        }
        // The project's packages, as the site has them: core reads them from vendor/composer/installed.json
        // (field types, blocks, translations and commands a package brings by being installed).
        symlink($this->projectVendor(), $this->starterRoot . '/vendor');
        // STARTER_API_ONLY=1 forces this for a whole run (ApiOnlyTest starts one as a subprocess).
        if ($apiOnly || getenv('STARTER_API_ONLY') === '1') {
            foreach (self::SITE_PATHS as $path) {
                exec('rm -rf ' . escapeshellarg($this->starterRoot . '/' . $path));
            }
        }
        // ApiOnlyTest reads this log back: one line per booted copy, naming the site paths it still has.
        $log = getenv('STARTER_API_ONLY_LOG');
        if (is_string($log) && $log !== '') {
            $left = array_filter(self::SITE_PATHS, fn (string $path): bool => file_exists($this->starterRoot . '/' . $path));
            file_put_contents($log, 'site paths left: ' . (implode(',', $left) ?: 'none') . "\n", FILE_APPEND);
        }

        // Clean globals, as a new process on the server has: nothing from tests that ran before.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            unset($_SESSION);
        }
        $_COOKIE = $_GET = $_POST = $_REQUEST = $_FILES = [];

        $this->setEnv('APP_KEY', 'starter-test-key-0123456789abcdef');
        $this->setEnv('APP_URL', 'http://localhost:8080');
        $this->setEnv('APP_DEBUG', $debug ? '1' : '0');
        TestDataPaths::use($this->starterRoot);

        $this->snapshots = (new SetupSnapshots())
            ->root('{starterTmp}', $this->starterRoot)
            // The link to the project's vendor/: not part of a step's state, and kept on restore.
            ->ignore('{starterTmp}/vendor')
            // A restored step replaced the files under the app booted before it.
            ->onRestore(function (): void {
                $this->starterApp = null;
                $this->setUpRestored = true;
            });
        $this->setUpRestored = false;
        $this->starterApp = null;

        // The link is no part of the state, so the step is keyed by what it installs: a package
        // can bring migrations and seeds, and a copy linked to another vendor/ seeds anew.
        $installed = (string) @file_get_contents($this->starterRoot . '/vendor/composer/installed.json');
        $this->snapshots->step('seeded ' . hash('xxh3', $installed), function (): bool {
            $this->console('migrate');
            $this->console('db:seed');

            return true;
        });
    }

    private function removeStarter(): void
    {
        // The next test is a new process as far as sessions go: no open session, no remembered id.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if (session_status() === PHP_SESSION_NONE) {
            session_id('');
        }
        $this->snapshots = null;
        $this->starterApp = null;
        $this->asWeb = false;
        foreach (['APP_KEY', 'APP_URL', 'APP_DEBUG'] as $name) {
            putenv($name);
            unset($_ENV[$name]);
        }
        TestDataPaths::release();
        if ($this->starterRoot !== '' && is_dir($this->starterRoot)) {
            exec('rm -rf ' . escapeshellarg($this->starterRoot));
        }
        $this->starterRoot = '';
    }

    /** A freshly booted app (one per request, like production). */
    private function app(): Application
    {
        // A request that ended in an error page leaves its session open; a new process has none.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        unset($_SESSION);
        if ($this->asWeb) {
            return $this->starterApp = WebApplication::boot($this->starterRoot);
        }
        $app = Application::create($this->starterRoot);
        $app->boot();

        return $this->starterApp = $app;
    }

    /** The container of the most recently booted app (boots one if none). */
    private function container(): Container
    {
        return ($this->starterApp ?? $this->app())->container();
    }

    /** @param array<string, string> $headers */
    private function get(string $uri, array $headers = []): TestResponse
    {
        return new TestResponse($this->send('GET', $uri, null, $headers));
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    private function post(string $uri, array $body, array $headers = []): TestResponse
    {
        return new TestResponse($this->send('POST', $uri, $body, $headers));
    }

    /**
     * A JSON API request: the body is encoded, and the response is asked for as JSON.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     */
    private function json(string $method, string $uri, ?array $body = null, array $headers = []): TestResponse
    {
        $headers += ['Accept' => 'application/json'];
        $request = (new Psr17Factory())->createServerRequest(strtoupper($method), $uri);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody((new Psr17Factory())->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
        }

        return new TestResponse($this->app()->handle($request));
    }

    /**
     * Signs in through the real login form (with its CSRF token), the way a browser does.
     *
     * @return array<string, string> the session cookies, to pass as ['Cookie' => ...] headers
     */
    private function signInEmployer(string $email = 'employer@example.com', string $password = 'demo-employer'): array
    {
        $form = $this->send('GET', '/employer/login');
        $cookies = $this->cookiesOf($form);
        preg_match('/name="_csrf_token" value="([^"]+)"/', (string) $form->getBody(), $match);

        $response = $this->send('POST', '/employer/login', [
            'email' => $email,
            'password' => $password,
            '_csrf_token' => $match[1] ?? '',
        ], ['Cookie' => $this->cookieHeader($cookies)]);

        return $this->cookiesOf($response) + $cookies;
    }

    /**
     * Signs in to Folio through its login form, as staff do.
     *
     * @return array<string, string> the session cookies, to pass as ['Cookie' => ...] headers
     */
    private function signInStaff(string $email = 'admin@example.com', string $password = 'demo-admin'): array
    {
        $form = $this->send('GET', '/folio/login');
        $cookies = $this->cookiesOf($form);
        preg_match('/name="_csrf_token" value="([^"]+)"/', (string) $form->getBody(), $match);

        $response = $this->send('POST', '/folio/login', [
            'email' => $email,
            'password' => $password,
            '_csrf_token' => html_entity_decode($match[1] ?? ''),
        ], ['Cookie' => $this->cookieHeader($cookies)]);

        return $this->cookiesOf($response) + $cookies;
    }

    /** @param array<string, string> $cookies */
    private function cookieHeader(array $cookies): string
    {
        return implode('; ', array_map(fn (string $name, string $value): string => $name . '=' . $value, array_keys($cookies), $cookies));
    }

    /** @return array<string, string> name => value, from the response's Set-Cookie headers */
    private function cookiesOf(ResponseInterface $response): array
    {
        $cookies = [];
        foreach ($response->getHeader('Set-Cookie') as $line) {
            [$pair] = explode(';', $line, 2);
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $cookies[trim($name)] = $value;
        }

        return $cookies;
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers a 'Cookie' header is also put into the request's cookie params
     */
    private function send(string $method, string $uri, ?array $body = null, array $headers = []): ResponseInterface
    {
        // The page cache keys a page by its host, so a web request carries the whole address.
        $request = (new Psr17Factory())->createServerRequest($method, $this->asWeb && str_starts_with($uri, '/') ? 'http://localhost:8080' . $uri : $uri);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if (isset($headers['Cookie'])) {
            parse_str(str_replace('; ', '&', $headers['Cookie']), $cookies);
            $request = $request->withCookieParams($cookies);
        }
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }

        return $this->app()->handle($request);
    }

    /** Runs a console command in this test's copy, in-process, as `php preflow <command>` would. */
    private function console(string ...$command): int
    {
        $previous = getcwd();
        chdir($this->starterRoot);
        ob_start();
        try {
            return (new Console($this->starterRoot))->run(['preflow', ...$command]);
        } finally {
            ob_end_clean();
            chdir($previous ?: '/');
        }
    }

    /** The vendor folder each copy links to: the project's own, so a copy has its installed packages. */
    private function projectVendor(): string
    {
        return dirname(__DIR__, 2) . '/vendor';
    }

    private function setEnv(string $name, string $value): void
    {
        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
    }
}
