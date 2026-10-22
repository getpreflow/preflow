<?php

declare(strict_types=1);

namespace Preflow\Auth\Tests\Tokens;

use Preflow\Auth\Tokens\Tokens;
use Preflow\Data\Migration\Migration;
use Preflow\Data\Migration\Schema;
use Preflow\Testing\DataTestCase;

final class TokensTest extends DataTestCase
{
    private int $now = 1_000_000;

    /** @var list<string> */
    private array $lockDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        /** @var Migration $migration */
        $migration = require dirname(__DIR__, 2) . '/migrations/2026_10_23_000000_auth_tokens.php';
        $migration->up(new Schema($this->getPdo()));
    }

    protected function tearDown(): void
    {
        foreach ($this->lockDirs as $dir) {
            array_map(unlink(...), glob($dir . '/*') ?: []);
            @rmdir($dir);
        }
        parent::tearDown();
    }

    private function tokens(string $storage = 'default', ?\Closure $clock = null): Tokens
    {
        $lockDir = sys_get_temp_dir() . '/preflow_tokens_locks_' . uniqid();
        $this->lockDirs[] = $lockDir;

        return new Tokens($this->dataManager(), $storage, $clock ?? fn (): int => $this->now, $lockDir);
    }

    public function test_issue_and_consume_once(): void
    {
        foreach (['sqlite', 'json'] as $storage) {
            $t = $this->tokens($storage);
            $token = $t->issue('confirm', 'user-1', 3600);

            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token, $storage);
            $this->assertSame('user-1', $t->consume($token, 'confirm'), $storage);
            $this->assertNull($t->consume($token, 'confirm'), $storage);
            $this->assertNull($t->consume('not-a-token', 'confirm'), $storage);
        }
    }

    public function test_expired_token_rejected(): void
    {
        foreach (['sqlite', 'json'] as $storage) {
            $t = $this->tokens($storage);
            $token = $t->issue('reset', 'user-1', 60);
            $this->now += 59;
            $kept = $t->issue('reset', 'user-2', 60);
            $this->now += 1;

            $this->assertNull($t->consume($token, 'reset'), $storage);
            $this->assertSame('user-2', $t->consume($kept, 'reset'), $storage);
            $this->now -= 60;
        }
    }

    public function test_wrong_purpose_rejected(): void
    {
        foreach (['sqlite', 'json'] as $storage) {
            $t = $this->tokens($storage);
            $token = $t->issue('confirm', 'user-1', 3600);

            $this->assertNull($t->consume($token, 'reset'), $storage);
            // Not spent by the wrong purpose.
            $this->assertSame('user-1', $t->consume($token, 'confirm'), $storage);
        }
    }

    public function test_token_stored_only_as_hash(): void
    {
        $token = $this->tokens()->issue('confirm', 'user-1', 3600);
        $rows = $this->dataManager()->raw('SELECT * FROM auth_tokens');

        $this->assertCount(1, $rows);
        $this->assertSame(hash('sha256', $token), $rows[0]['id']);
        $this->assertSame(['id', 'purpose', 'subject', 'expires_at'], array_keys($rows[0]));
        $this->assertStringNotContainsString($token, json_encode($rows, JSON_THROW_ON_ERROR));
        $this->assertSame($this->now + 3600, (int) $rows[0]['expires_at']);

        $jsonToken = $this->tokens('json')->issue('confirm', 'user-1', 3600);
        $base = (new \ReflectionProperty($this->getJsonDriver(), 'basePath'))->getValue($this->getJsonDriver());
        $files = glob($base . '/auth_tokens/*') ?: [];
        $stored = implode('', $files) . implode('', array_map(file_get_contents(...), $files));
        $this->assertNotSame('', $stored);
        $this->assertStringNotContainsString($jsonToken, $stored);
        $this->assertStringContainsString(hash('sha256', $jsonToken), $stored);
    }

    public function test_revoke_all(): void
    {
        foreach (['sqlite', 'json'] as $storage) {
            $t = $this->tokens($storage);
            $a = $t->issue('reset', 'user-1', 3600);
            $b = $t->issue('reset', 'user-1', 3600);
            $other = $t->issue('confirm', 'user-1', 3600);
            $otherUser = $t->issue('reset', 'user-2', 3600);

            $this->assertSame(2, $t->revokeAll('reset', 'user-1'), $storage);
            $this->assertNull($t->consume($a, 'reset'), $storage);
            $this->assertNull($t->consume($b, 'reset'), $storage);
            $this->assertSame('user-1', $t->consume($other, 'confirm'), $storage);
            $this->assertSame('user-2', $t->consume($otherUser, 'reset'), $storage);
            $this->assertSame(0, $t->revokeAll('reset', 'user-1'), $storage);
        }
    }

    public function test_prune_deletes_expired_rows(): void
    {
        foreach (['sqlite', 'json'] as $storage) {
            $t = $this->tokens($storage);
            $t->issue('reset', 'user-1', 60);
            $t->issue('reset', 'user-2', 120);
            $live = $t->issue('reset', 'user-3', 3600);
            $this->now += 120;

            $this->assertSame(2, $t->prune(), $storage);
            $this->assertSame(0, $t->prune(), $storage);
            $this->assertSame('user-3', $t->consume($live, 'reset'), $storage);
            $this->now -= 120;
        }
    }

    public function test_consume_is_decided_by_the_delete(): void
    {
        // A second request spends the token between this one's read and its delete.
        $hook = null;
        $t = $this->tokens('sqlite', function () use (&$hook): int {
            if ($hook !== null) {
                [$run, $hook] = [$hook, null];
                $run();
            }

            return $this->now;
        });
        $other = $this->tokens('sqlite');
        $token = $t->issue('confirm', 'user-1', 3600);
        $hook = static function () use ($other, $token): void {
            // Runs at consume()'s clock read, before its DELETE.
            TokensTest::$spent = $other->consume($token, 'confirm');
        };

        $this->assertNull($t->consume($token, 'confirm'));
        $this->assertSame('user-1', self::$spent);
    }

    public static ?string $spent = null;

    public function test_json_consume_locks(): void
    {
        $lockDir = sys_get_temp_dir() . '/preflow_tokens_locks_' . uniqid();
        $this->lockDirs[] = $lockDir;
        $held = [];
        $lockFile = null;
        $t = new Tokens($this->dataManager(), 'json', function () use (&$lockFile, &$held): int {
            if ($lockFile !== null && is_file($lockFile)) {
                $probe = fopen($lockFile, 'c');
                $held[] = !flock($probe, LOCK_EX | LOCK_NB);
                fclose($probe);
            }

            return $this->now;
        }, $lockDir);
        $token = $t->issue('confirm', 'user-1', 3600);
        $lockFile = $lockDir . '/tokens-' . hash('sha256', $token) . '.lock';

        $this->assertSame('user-1', $t->consume($token, 'confirm'));
        $this->assertFileExists($lockFile);
        $this->assertNotEmpty($held);
        $this->assertNotContains(false, $held, 'the lock is held while consume() reads and deletes');
    }
}
