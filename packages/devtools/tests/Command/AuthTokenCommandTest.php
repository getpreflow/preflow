<?php

declare(strict_types=1);

namespace Preflow\DevTools\Tests\Command;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\ConfigUserProvider;
use Preflow\Auth\TokenGuard;
use Preflow\Core\Application;
use Preflow\DevTools\Command\AuthTokenCommand;
use Preflow\DevTools\Command\MigrateCommand;

/** auth:token against a temp SQLite site with the skeleton's user_tokens migration. */
final class AuthTokenCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/auth_token_cmd_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        mkdir($this->dir . '/migrations', 0777, true);
        copy(dirname(__DIR__, 3) . '/skeleton/migrations/2026_04_12_001_create_user_tokens.php', $this->dir . '/migrations/2026_04_12_001_create_user_tokens.php');
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'auth-token-test-key-0123456789abcd'];\n");
        file_put_contents($this->dir . '/config/data.php', '<?php return ["default" => "sqlite", "drivers" => ["sqlite" => ["path" => ' . var_export($this->dir . '/app.sqlite', true) . ']]];');
        $this->auth(['api' => ['class' => TokenGuard::class, 'provider' => 'users']]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** @param array<string, mixed> $guards */
    private function auth(array $guards): void
    {
        file_put_contents($this->dir . '/config/auth.php', '<?php return ' . var_export([
            'default_guard' => 'api',
            'guards' => $guards,
            'providers' => ['users' => ['class' => ConfigUserProvider::class, 'users' => [['email' => 'ada@example.com', 'password_hash' => '', 'roles' => []]]]],
        ], true) . ';');
    }

    private function app(): Application
    {
        $app = Application::create($this->dir);
        $app->boot();

        return $app;
    }

    /** @return array{0:int,1:string} */
    private function token(array $args, bool $migrate = true): array
    {
        if ($migrate && !is_file($this->dir . '/app.sqlite')) {
            ob_start();
            $code = (new MigrateCommand(fn () => $this->app()))->execute([]);
            $out = (string) ob_get_clean();
            $this->assertSame(0, $code, $out);
        }
        ob_start();
        $code = (new AuthTokenCommand(fn () => $this->app()))->execute($args);

        return [$code, (string) ob_get_clean()];
    }

    private function issue(string $subject = 'ada@example.com', string ...$extra): string
    {
        [$code, $out] = $this->token([$subject, ...$extra]);
        $this->assertSame(0, $code, $out);
        $this->assertMatchesRegularExpression('/^Token for ' . preg_quote($subject, '/') . ' \(\w+\), shown once:\n[0-9a-f]{64}\n$/', $out);

        return trim(substr($out, (int) strrpos($out, "\n", -2)));
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return (new \PDO('sqlite:' . $this->dir . '/app.sqlite'))->query('SELECT * FROM user_tokens')->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function test_issues_a_token_and_stores_only_its_hash(): void
    {
        $plain = $this->issue();

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame(hash('sha256', $plain), $rows[0]['tokenHash']);
        $this->assertStringNotContainsString($plain, (string) json_encode($rows));
        $this->assertSame('cli', $rows[0]['name']);
        $this->assertNotEmpty($rows[0]['createdAt']);
    }

    public function test_issued_token_authenticates_through_the_token_guard(): void
    {
        $plain = $this->issue('ada@example.com', '--name=laptop');

        $app = $this->app();
        $guard = $app->container()->get(\Preflow\Auth\AuthManager::class)->guard('api');
        $user = $guard->user(new ServerRequest('GET', '/api/x', ['Authorization' => 'Bearer ' . $plain]));

        $this->assertSame('ada@example.com', $user?->getAuthId());
        $this->assertSame('laptop', $this->rows()[0]['name']);
    }

    public function test_list_shows_name_but_neither_token_nor_hash(): void
    {
        $plain = $this->issue('ada@example.com', '--name=laptop');

        [$code, $out] = $this->token(['--list', 'ada@example.com']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('laptop', $out);
        $this->assertStringContainsString($this->rows()[0]['uuid'], $out);
        $this->assertStringNotContainsString($plain, $out);
        $this->assertStringNotContainsString(hash('sha256', $plain), $out);
    }

    public function test_revoke_deletes_the_token(): void
    {
        $this->issue();
        $uuid = $this->rows()[0]['uuid'];

        [$code, $out] = $this->token(['--revoke=' . $uuid]);
        $this->assertSame(0, $code);
        $this->assertSame("Revoked.\n", $out);
        $this->assertSame([], $this->rows());

        [$code, $out] = $this->token(['--revoke=' . $uuid]);
        $this->assertSame(1, $code);
        $this->assertSame("Error: No token {$uuid}.\n", $out);
    }

    public function test_unknown_user_exits_1(): void
    {
        [$code, $out] = $this->token(['nobody@example.com']);

        $this->assertSame(1, $code);
        $this->assertSame("Error: No user nobody@example.com.\n", $out);
        $this->assertSame([], $this->rows());
    }

    public function test_user_can_be_found_by_id(): void
    {
        $this->issue('ada@example.com');
        $id = $this->app()->container()->get(\Preflow\Auth\UserProviderInterface::class)->findByCredentials(['email' => 'ada@example.com'])->getAuthId();

        [$code] = $this->token([$id]);

        $this->assertSame(0, $code);
        $this->assertCount(2, $this->rows());
    }

    public function test_no_token_guard_configured_exits_1(): void
    {
        $this->auth(['web' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'users']]);

        [$code, $out] = $this->token(['ada@example.com']);

        $this->assertSame(1, $code);
        $this->assertStringStartsWith('Error: No token guard is configured', $out);
        $this->assertSame(1, substr_count($out, "\n"));
    }

    public function test_missing_table_says_to_migrate(): void
    {
        [$code, $out] = $this->token(['ada@example.com'], migrate: false);

        $this->assertSame(1, $code);
        $this->assertSame("Error: Run the user_tokens migration first.\n", $out);
    }

    public function test_other_storage_errors_print_their_own_message(): void
    {
        // The table exists but lacks a column: the error names user_tokens, yet the table is not missing.
        (new \PDO('sqlite:' . $this->dir . '/app.sqlite'))->exec('CREATE TABLE user_tokens (uuid TEXT)');

        [$code, $out] = $this->token(['ada@example.com'], migrate: false);

        $this->assertSame(1, $code);
        $this->assertStringStartsWith('Error: ', $out);
        $this->assertStringNotContainsString('migration', $out);
        $this->assertStringContainsString('user_tokens', $out);
    }
}
