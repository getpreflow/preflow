<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Console;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\DevTools\Config\ConfigFileEditor;
use Preflow\Folio\Console\InstallCommand;
use Preflow\Folio\Tests\Support\InstallTestApp;
use Preflow\Folio\Tests\Support\TempRoots;
use Preflow\Testing\ChecksFrameBalance;
use Psr\Http\Message\ResponseInterface;

/**
 * folio:install on temp copies of packages/skeleton (the empty app) and packages/starter (an app
 * with its own config/auth.php), with folio and rbac from the monorepo (InstallTestApp).
 */
final class InstallCommandTest extends TestCase
{
    use ChecksFrameBalance;
    use InstallTestApp;

    private const string PASSWORD = 'a long enough password';

    /**
     * @param list<string> $args
     * @return array{int, string} exit code, output
     */
    private function install(string $root, array $args, string $input = '', bool $terminal = false, ?\Closure $stty = null): array
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $input);
        rewind($stream);
        ob_start();
        try {
            $code = (new InstallCommand($root, $stream, $terminal, $stty))->execute($args);
        } finally {
            $output = (string) ob_get_clean();
        }

        return [$code, $output];
    }

    /** @return array<mixed> */
    private function config(string $root, string $file): array
    {
        return (new ConfigFileEditor($root . '/config'))->read($file) ?? $this->fail("config/{$file} does not evaluate");
    }

    public function test_without_rbac_stops_before_writing(): void
    {
        $root = $this->installApp('skeleton', without: ['rbac']);
        $before = $this->files($root, true);

        [$code, $output] = $this->install($root, ['--email=a@example.test', '--no-password']);

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('composer require preflow/rbac', $output);
        $this->assertStringNotContainsString('folio-setup', $output, 'the guide link is for apps with preflow/roles');
        $this->assertSame($before, $this->files($root, true), 'nothing written');

        $root = $this->installApp('skeleton', without: ['rbac'], with: ['roles']);
        $before = $this->files($root, true);

        [$code, $output] = $this->install($root, ['--email=a@example.test', '--no-password']);

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('composer require preflow/rbac', $output);
        $this->assertStringContainsString('preflow/roles', $output);
        $this->assertStringContainsString('https://preflow.dev/docs/folio/folio-setup', $output);
        $this->assertSame($before, $this->files($root, true), 'nothing written');
    }

    #[RunInSeparateProcess]
    public function test_fresh_skeleton_gets_working_admin(): void
    {
        $root = $this->installApp('skeleton');

        [$code, $output] = $this->install($root, ['--email=a@example.test', '--name=A'], self::PASSWORD . "\n");

        $this->assertSame(0, $code, $output);
        $this->assertSame(
            [\Preflow\Folio\FolioServiceProvider::class, \Preflow\Rbac\RbacServiceProvider::class, 'App\Providers\AppServiceProvider'],
            array_map(static fn (string $c): string => ltrim($c, '\\'), $this->config($root, 'providers.php')),
        );
        $auth = $this->config($root, 'auth.php');
        $this->assertSame('staff', $auth['default_guard']);
        $this->assertSame(['/folio'], $auth['session']['paths']);
        $this->assertSame('/folio', $this->config($root, 'folio.php')['path']);
        $this->assertSame('sqlite', $this->config($root, 'media.php')['storage']);
        $this->assertStringNotContainsString(self::PASSWORD, $output, 'the password is never printed');
        $this->assertStringContainsString('http://localhost:8080/folio', $output, 'the summary names the admin');

        // Signing in reaches the dashboard.
        require $root . '/vendor/autoload.php';
        TempRoots::isolateSessions();
        $app = Application::create($root);
        $app->boot();
        $this->assertSame(200, $this->send($app, 'GET', '/folio/login')->getStatusCode());
        $this->assertSame(302, $this->send($app, 'GET', '/folio')->getStatusCode(), 'signed out: sent to the login');
        $login = $this->send($app, 'POST', '/folio/login', ['email' => 'a@example.test', 'password' => self::PASSWORD, '_csrf_token' => (string) ($_SESSION['_csrf_token'] ?? '')]);
        $this->assertSame('/folio', $login->getHeaderLine('Location'));
        $dashboard = $this->send($app, 'GET', '/folio');
        $this->assertSame(200, $dashboard->getStatusCode());
        $this->assertStringContainsString('folio-dash-band', (string) $dashboard->getBody());
    }

    /** @param array<string, string> $body */
    private function send(Application $app, string $method, string $uri, array $body = []): ResponseInterface
    {
        $request = (new Psr17Factory())->createServerRequest($method, $uri, ['REMOTE_ADDR' => '203.0.113.7']);
        if (session_id() !== '') {
            $request = $request->withCookieParams([session_name() => session_id()]);
        }
        if ($method !== 'GET') {
            $request = $request->withHeader('Sec-Fetch-Site', 'same-origin')->withParsedBody($body);
        }

        return $app->handle($request);
    }

    public function test_starter_keeps_default_guard(): void
    {
        $root = $this->installApp('starter');

        [$code, $output] = $this->install($root, ['--email=a@example.test', '--name=A', '--no-password']);

        $this->assertSame(0, $code, $output);
        $auth = $this->config($root, 'auth.php');
        $this->assertSame('employers', $auth['default_guard'], 'the app\'s default guard is untouched');
        $this->assertSame(['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'staff', 'population' => 'staff'], $auth['guards']['staff']);
        $this->assertSame(\Preflow\Auth\Record\RecordUserProvider::class, $auth['providers']['staff']['class']);
        $this->assertSame('staff', $auth['providers']['staff']['type']);
        $this->assertSame(['/employer', '/folio'], $auth['session']['paths']);
        $this->assertSame('staff', $this->config($root, 'folio.php')['guard']);
        $this->assertSame(
            [\Preflow\Folio\FolioServiceProvider::class, \Preflow\Rbac\RbacServiceProvider::class, 'App\Providers\AppServiceProvider'],
            array_map(static fn (string $c): string => ltrim($c, '\\'), $this->config($root, 'providers.php')),
        );
        $this->assertStringContainsString('created the admin a@example.test', $output);
        $this->assertStringContainsString('php preflow staff:password a@example.test', $output, '--no-password says how to set one');
    }

    public function test_wrong_existing_staff_guard_refuses(): void
    {
        $root = $this->installApp('starter');
        $edit = (new ConfigFileEditor($root . '/config'))->addKey('auth.php', ['guards'], 'staff', "[\n    'class' => \\Preflow\\Auth\\SessionGuard::class,\n    'provider' => 'users',\n    'population' => 'members',\n]");
        $this->assertSame('changed', $edit->status, $edit->message);
        $before = $this->files($root, true);

        [$code, $output] = $this->install($root, ['--email=a@example.test', '--no-password']);

        $this->assertNotSame(0, $code, $output);
        $this->assertStringContainsString('"staff"', $output);
        $this->assertStringContainsString('"members"', $output);
        $this->assertSame($before, $this->files($root, true), 'no file changed');

        // No staff guard yet, but a "staff" provider that is not rbac's staff accounts: no guard is put on it.
        $root = $this->installApp('starter');
        $edit = (new ConfigFileEditor($root . '/config'))->addKey('auth.php', ['providers'], 'staff', "[\n    'class' => \\Preflow\\Auth\\DataManagerUserProvider::class,\n    'model' => 'App\\Models\\User',\n]");
        $this->assertSame('changed', $edit->status, $edit->message);
        $before = $this->files($root, true);

        [$code, $output] = $this->install($root, ['--email=a@example.test', '--no-password']);

        $this->assertNotSame(0, $code, $output);
        $this->assertStringContainsString('the provider "staff" (Preflow\Auth\DataManagerUserProvider)', $output);
        $this->assertSame($before, $this->files($root, true), 'no file changed');
    }

    public function test_roles_file_has_only_declared_permissions(): void
    {
        $root = $this->installApp('skeleton');
        // A content type of the app's own: the editor gets it.
        @mkdir($root . '/config/models', 0777, true);
        file_put_contents($root . '/config/models/news.json', (string) json_encode([
            'key' => 'news', 'table' => 'news', 'storage' => 'sqlite', 'id_field' => 'uuid', 'label' => 'News',
            'fields' => ['title' => ['type' => 'string']],
        ]));

        [$code, $output] = $this->install($root, ['--email=a@example.test', '--no-password']);

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('rbac installed.', $output);
        $this->assertStringNotContainsString('not a declared permission', $output);
        $roles = $this->config($root, 'roles.php');
        $this->assertSame(['*'], $roles['staff']['admin']);
        $editor = $roles['staff']['editor'];
        foreach (['folio.dashboard', 'assets.view', 'assets.upload', 'assets.edit', 'news.*'] as $permission) {
            $this->assertContains($permission, $editor);
        }
        $this->assertNotContains('staff.*', $editor, 'never the staff accounts');
    }

    public function test_second_run_changes_nothing(): void
    {
        $root = $this->installApp('skeleton');
        [$code, $output] = $this->install($root, ['--email=a@example.test', '--name=A'], self::PASSWORD . "\n");
        $this->assertSame(0, $code, $output);
        $before = $this->files($root);

        // No terminal, no --email: the admin exists, so nothing needs asking.
        [$code, $output] = $this->install($root, []);

        $this->assertSame(0, $code, $output);
        $this->assertSame($before, $this->files($root), 'no file changed');
        foreach (['config/providers.php', 'config/folio.php', 'config/auth.php', 'config/media.php', 'config/roles.php', 'first admin'] as $step) {
            $this->assertMatchesRegularExpression('~' . preg_quote($step, '~') . '.*already set up~i', $output);
        }
        $this->assertStringNotContainsString('Written:', $output);
        $this->assertStringNotContainsString('Changed:', $output);
    }

    public function test_resumes_after_partial_run(): void
    {
        $root = $this->installApp('skeleton');
        // A run that stopped after the providers and config/folio.php.
        $editor = new ConfigFileEditor($root . '/config');
        $editor->addToList('providers.php', ['\Preflow\Folio\FolioServiceProvider::class', '\Preflow\Rbac\RbacServiceProvider::class']);
        $editor->create('folio.php', "<?php\n\nreturn [\n    'path' => '/folio',\n];\n");
        $providers = (string) file_get_contents($root . '/config/providers.php');

        [$code, $output] = $this->install($root, ['--email=a@example.test', '--name=A'], self::PASSWORD . "\n");

        $this->assertSame(0, $code, $output);
        $this->assertMatchesRegularExpression('~config/providers.php.*already set up~i', $output);
        $this->assertMatchesRegularExpression('~config/folio.php.*already set up~i', $output);
        $this->assertSame($providers, (string) file_get_contents($root . '/config/providers.php'));
        $this->assertSame('staff', $this->config($root, 'auth.php')['default_guard']);
        $this->assertFileExists($root . '/config/roles.php');
        $this->assertStringContainsString('created the admin a@example.test', $output);
    }

    public function test_existing_path_is_kept(): void
    {
        $root = $this->installApp('skeleton');
        file_put_contents($root . '/config/folio.php', "<?php\n\nreturn [\n    'path' => '/folio',\n];\n");
        $folio = (string) file_get_contents($root . '/config/folio.php');

        [$code, $output] = $this->install($root, ['--path=/admin', '--email=a@example.test', '--no-password']);

        $this->assertSame(0, $code, $output);
        $this->assertSame($folio, (string) file_get_contents($root . '/config/folio.php'), 'never changed');
        $this->assertStringContainsString('/folio', $output);
        $this->assertStringContainsString('--path=/admin', $output, 'says the option was not applied');
        $this->assertSame(['/folio'], $this->config($root, 'auth.php')['session']['paths'], 'the path in use gets the session');
    }

    public function test_no_tty_without_email_fails_fast(): void
    {
        $root = $this->installApp('skeleton');
        $before = $this->files($root, true);
        $start = hrtime(true);

        [$code, $output] = $this->install($root, []);

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('--email', $output);
        $this->assertStringContainsString('--no-password', $output);
        $this->assertSame($before, $this->files($root, true), 'nothing written');
        $this->assertLessThan(20, (hrtime(true) - $start) / 1e9, 'no hang');

        // --email without a piped password (and without --no-password) stops before writing too.
        [$code, $output] = $this->install($root, ['--email=a@example.test']);

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('No password came on STDIN', $output);
        $this->assertStringContainsString('--no-password', $output);
        $this->assertSame($before, $this->files($root, true), 'nothing written');
    }

    public function test_dry_run_changes_nothing(): void
    {
        $root = $this->installApp('starter');
        $before = $this->files($root, true);

        [$code, $output] = $this->install($root, ['--dry-run']);

        $this->assertSame(0, $code, $output);
        $this->assertSame($before, $this->files($root, true), 'nothing written, no database made');
        foreach (['config/providers.php', 'config/folio.php', 'config/auth.php', 'config/media.php', 'config/roles.php', 'php preflow migrate', 'php preflow rbac:install'] as $planned) {
            $this->assertStringContainsString($planned, $output);
        }
        $this->assertStringContainsString("'population' => 'staff'", $output, 'shows what it would add');
    }

    public function test_password_never_an_argument(): void
    {
        $root = $this->installApp('skeleton');
        $before = $this->files($root, true);

        [$code, $output] = $this->install($root, ['--email=a@example.test', '--password=x']);

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('Unknown option --password', $output);
        $this->assertSame($before, $this->files($root, true));
    }

    public function test_terminal_asks_with_the_password_hidden(): void
    {
        $root = $this->installApp('skeleton');
        $stty = [];
        $fake = static function (string $args) use (&$stty): string {
            $stty[] = $args;

            return $args === '-g' ? 'saved-settings' : '';
        };

        [$code, $output] = $this->install($root, [], "a@example.test\nA\nshort\n" . self::PASSWORD . "\n" . self::PASSWORD . "\n", true, $fake);

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('E-mail address of the first admin:', $output);
        $this->assertStringContainsString('shorter than 10 characters', $output, 'Folio\'s password_min');
        $this->assertStringContainsString('created the admin a@example.test', $output);
        $this->assertStringNotContainsString(self::PASSWORD, $output);
        $this->assertSame(['-g', '-echo', "'saved-settings'", '-echo', "'saved-settings'", '-echo', "'saved-settings'"], $stty, 'echo off for each password line, restored after');
    }

    public function test_file_it_cannot_edit_is_left_alone_and_the_rest_done(): void
    {
        $root = $this->installApp('skeleton');
        file_put_contents($root . '/config/providers.php', "<?php\n\nreturn array(\n    \\App\\Providers\\AppServiceProvider::class,\n);\n");
        $providers = (string) file_get_contents($root . '/config/providers.php');

        [$code, $output] = $this->install($root, ['--email=a@example.test', '--no-password']);

        $this->assertSame(1, $code, $output);
        $this->assertSame($providers, (string) file_get_contents($root . '/config/providers.php'), 'byte-identical');
        $this->assertStringContainsString('\\Preflow\\Folio\\FolioServiceProvider::class,', $output, 'the snippet to add by hand');
        $this->assertMatchesRegularExpression('~Needs you.*config/providers.php~', $output);
        $this->assertFileExists($root . '/config/folio.php', 'the steps it could do are done');
        $this->assertFileExists($root . '/config/auth.php');
        $this->assertFileDoesNotExist($root . '/config/roles.php', 'what needs Folio to boot waits');
    }

    public function test_provider_order_is_checked_as_a_whole(): void
    {
        $folio = '\Preflow\Folio\FolioServiceProvider::class';
        $rbac = '\Preflow\Rbac\RbacServiceProvider::class';
        $app = '\App\Providers\AppServiceProvider::class';
        $order = "{$folio},\n{$rbac},\n{$app},";
        // An app provider before Folio or rbac; Folio with app providers but without rbac (rbac
        // belongs between them, where the editor cannot insert): refused, the file unchanged.
        foreach ([[$app, $folio], [$app, $rbac], [$app, $folio, $rbac], [$folio, $app], [$rbac, $folio, $app]] as $list) {
            $root = $this->installApp('skeleton');
            file_put_contents($root . '/config/providers.php', "<?php\n\nreturn [\n    " . implode(",\n    ", $list) . ",\n];\n");
            $before = (string) file_get_contents($root . '/config/providers.php');

            [$code, $output] = $this->install($root, ['--email=a@example.test', '--no-password']);

            $this->assertSame(1, $code, $output);
            $this->assertSame($before, (string) file_get_contents($root . '/config/providers.php'), implode(', ', $list));
            $this->assertStringContainsString(self::indented($order), $output, 'the whole list in the right order');
            $this->assertMatchesRegularExpression('~Needs you.*config/providers.php~', $output);
        }

        // Folio, rbac, then the app's: set up already.
        $root = $this->installApp('skeleton');
        file_put_contents($root . '/config/providers.php', "<?php\n\nreturn [\n    {$folio},\n    {$rbac},\n    {$app},\n];\n");
        $before = (string) file_get_contents($root . '/config/providers.php');
        [$code, $output] = $this->install($root, ['--email=a@example.test', '--no-password']);
        $this->assertSame(0, $code, $output);
        $this->assertMatchesRegularExpression('~config/providers.php.*already set up~', $output);
        $this->assertSame($before, (string) file_get_contents($root . '/config/providers.php'));

        // rbac before the app's, Folio missing: Folio goes in at the start.
        $root = $this->installApp('skeleton');
        file_put_contents($root . '/config/providers.php', "<?php\n\nreturn [\n    {$rbac},\n    {$app},\n];\n");
        [$code, $output] = $this->install($root, ['--email=a@example.test', '--no-password']);
        $this->assertSame(0, $code, $output);
        $this->assertSame(
            [\Preflow\Folio\FolioServiceProvider::class, \Preflow\Rbac\RbacServiceProvider::class, 'App\Providers\AppServiceProvider'],
            array_map(static fn (string $c): string => ltrim($c, '\\'), $this->config($root, 'providers.php')),
        );
    }

    private static function indented(string $text): string
    {
        return '      ' . str_replace("\n", "\n      ", $text);
    }

    public function test_folio_guard_without_auth_file_refuses(): void
    {
        $root = $this->installApp('skeleton');
        file_put_contents($root . '/config/folio.php', "<?php\n\nreturn ['path' => '/folio', 'guard' => 'admins'];\n");
        $before = $this->files($root, true);

        [$code, $output] = $this->install($root, ['--email=a@example.test', '--no-password']);

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('"admins"', $output);
        $this->assertStringContainsString('Nothing was changed.', $output);
        $this->assertSame($before, $this->files($root, true));
    }

    public function test_password_that_is_not_utf8_is_asked_again(): void
    {
        $root = $this->installApp('skeleton');
        $fake = static fn (string $args): string => $args === '-g' ? 'saved-settings' : '';

        [$code, $output] = $this->install($root, [], "a@example.test\nA\n\xff\xfe not text at all\n" . self::PASSWORD . "\n" . self::PASSWORD . "\n", true, $fake);

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('not valid UTF-8', $output);
        $this->assertStringContainsString('created the admin a@example.test', $output);
    }
}
