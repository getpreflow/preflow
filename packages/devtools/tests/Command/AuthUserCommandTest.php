<?php

declare(strict_types=1);

namespace Preflow\DevTools\Tests\Command;

use PHPUnit\Framework\TestCase;
use Preflow\Core\EnvLoader;
use Preflow\DevTools\Command\AuthUserCommand;

final class AuthUserCommandTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/preflow_auth_user_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->tmpDir);
        foreach (['FOLIO_ADMIN_EMAIL', 'FOLIO_ADMIN_PASSWORD_HASH', 'FOLIO_EDITOR_EMAIL', 'FOLIO_EDITOR_PASSWORD_HASH'] as $key) {
            putenv($key);
            unset($_ENV[$key]);
        }
    }

    public function test_writes_email_and_hash_into_empty_entries(): void
    {
        file_put_contents($this->tmpDir . '/.env', "APP_KEY=abc\nFOLIO_ADMIN_EMAIL=\nFOLIO_ADMIN_PASSWORD_HASH=\nMAIL_DSN=\n");

        [$code, $out] = $this->runCommand([], "me@example.com\nsecret-pass\nsecret-pass\n");

        $this->assertSame(0, $code, $out);
        $env = file_get_contents($this->tmpDir . '/.env');
        $this->assertStringContainsString("FOLIO_ADMIN_EMAIL=me@example.com\n", $env);
        $this->assertStringContainsString("APP_KEY=abc\n", $env);
        $this->assertStringContainsString("MAIL_DSN=\n", $env);
        $this->assertSame(1, substr_count($env, 'FOLIO_ADMIN_PASSWORD_HASH='));

        EnvLoader::load($this->tmpDir . '/.env');
        $this->assertSame('me@example.com', getenv('FOLIO_ADMIN_EMAIL'));
        $this->assertTrue(password_verify('secret-pass', (string) getenv('FOLIO_ADMIN_PASSWORD_HASH')));
    }

    public function test_never_prints_the_password_or_hash(): void
    {
        file_put_contents($this->tmpDir . '/.env', "FOLIO_ADMIN_EMAIL=\n");

        [, $out] = $this->runCommand([], "me@example.com\nsecret-pass\nsecret-pass\n");

        $this->assertStringNotContainsString('secret-pass', $out);
        $this->assertStringNotContainsString('$2y$', $out);
        $this->assertStringContainsString('FOLIO_ADMIN_PASSWORD_HASH', $out);
    }

    public function test_replaces_an_existing_login_and_appends_missing_keys(): void
    {
        file_put_contents($this->tmpDir . '/.env', "FOLIO_ADMIN_EMAIL=old@example.com\nAPP_DEBUG=1");

        [$code] = $this->runCommand(['--email=new@example.com'], "another-pass\nanother-pass\n");

        $this->assertSame(0, $code);
        $env = file_get_contents($this->tmpDir . '/.env');
        $this->assertStringNotContainsString('old@example.com', $env);
        $this->assertSame(1, substr_count($env, 'FOLIO_ADMIN_EMAIL='));
        $this->assertStringContainsString("APP_DEBUG=1\n", $env);

        EnvLoader::load($this->tmpDir . '/.env');
        $this->assertTrue(password_verify('another-pass', (string) getenv('FOLIO_ADMIN_PASSWORD_HASH')));
    }

    public function test_a_prefix_names_another_login(): void
    {
        file_put_contents($this->tmpDir . '/.env', "FOLIO_ADMIN_EMAIL=me@example.com\n");

        [$code] = $this->runCommand(['FOLIO_EDITOR', '--email=ed@example.com'], "editor-pass\neditor-pass\n");

        $this->assertSame(0, $code);
        $env = file_get_contents($this->tmpDir . '/.env');
        $this->assertStringContainsString("FOLIO_ADMIN_EMAIL=me@example.com\n", $env);
        $this->assertStringContainsString("FOLIO_EDITOR_EMAIL=ed@example.com\n", $env);
        $this->assertStringContainsString('FOLIO_EDITOR_PASSWORD_HASH=', $env);
    }

    public function test_mismatched_passwords_change_nothing(): void
    {
        $before = "FOLIO_ADMIN_EMAIL=\n";
        file_put_contents($this->tmpDir . '/.env', $before);

        [$code] = $this->runCommand(['--email=me@example.com'], "secret-pass\nother-pass\n");

        $this->assertSame(1, $code);
        $this->assertSame($before, file_get_contents($this->tmpDir . '/.env'));
    }

    public function test_rejects_a_short_password_and_a_bad_email(): void
    {
        file_put_contents($this->tmpDir . '/.env', "\n");

        [$short] = $this->runCommand(['--email=me@example.com'], "short\nshort\n");
        [$email] = $this->runCommand(['--email=not-an-email'], "secret-pass\nsecret-pass\n");

        $this->assertSame(1, $short);
        $this->assertSame(1, $email);
        $this->assertSame("\n", file_get_contents($this->tmpDir . '/.env'));
    }

    public function test_rejects_a_prefix_that_is_not_an_env_name(): void
    {
        file_put_contents($this->tmpDir . '/.env', "\n");

        [$code] = $this->runCommand(['folio-admin', '--email=me@example.com'], "secret-pass\nsecret-pass\n");

        $this->assertSame(1, $code);
    }

    public function test_fails_when_env_missing(): void
    {
        [$code] = $this->runCommand(['--email=me@example.com'], "secret-pass\nsecret-pass\n");

        $this->assertSame(1, $code);
        $this->assertFileDoesNotExist($this->tmpDir . '/.env');
    }

    /** @return array{0: int, 1: string} */
    private function runCommand(array $args, string $input): array
    {
        $in = fopen('php://memory', 'r+');
        fwrite($in, $input);
        rewind($in);
        $out = fopen('php://memory', 'r+');

        $code = (new AuthUserCommand())->executeInDir($this->tmpDir, $args, $in, $out);

        rewind($out);
        return [$code, (string) stream_get_contents($out)];
    }
}
