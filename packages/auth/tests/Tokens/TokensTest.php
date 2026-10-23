<?php

declare(strict_types=1);

namespace Preflow\Auth\Tests\Tokens;

use PHPUnit\Framework\Attributes\Group;
use Preflow\Auth\Tokens\Tokens;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\MysqlDriver;
use Preflow\Data\Migration\Migration;
use Preflow\Data\Migration\Schema;
use Preflow\Testing\DataTestCase;
use Preflow\Testing\MysqlTestDatabase;

final class TokensTest extends DataTestCase
{
    private int $now = 1_000_000;

    /** @var list<string> */
    private array $lockDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrate($this->getPdo());
    }

    private function migrate(\PDO $pdo): void
    {
        /** @var Migration $migration */
        $migration = require dirname(__DIR__, 2) . '/migrations/2026_10_23_000000_auth_tokens.php';
        $migration->up(new Schema($pdo));
    }

    /** The rest of the test on MySQL: 'default' and 'mysql' are a migrated MySQL storage. */
    private function onMysql(): void
    {
        $this->migrate($this->useMysql()->pdo());
    }

    /** @return list<string> the storages a looping test covers */
    private function storages(): array
    {
        return $this->usesMysql() ? ['mysql'] : ['sqlite', 'json'];
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
        foreach ($this->storages() as $storage) {
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
        foreach ($this->storages() as $storage) {
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
        foreach ($this->storages() as $storage) {
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
        foreach ($this->storages() as $storage) {
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
        foreach ($this->storages() as $storage) {
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
        $sql = $this->usesMysql() ? 'mysql' : 'sqlite';
        $t = $this->tokens($sql, function () use (&$hook): int {
            if ($hook !== null) {
                [$run, $hook] = [$hook, null];
                $run();
            }

            return $this->now;
        });
        $other = $this->tokens($sql);
        $token = $t->issue('confirm', 'user-1', 3600);
        $hook = static function () use ($other, $token): void {
            // Runs at consume()'s clock read, before its DELETE.
            TokensTest::$spent = $other->consume($token, 'confirm');
        };

        $this->assertNull($t->consume($token, 'confirm'));
        $this->assertSame('user-1', self::$spent);
    }

    public static ?string $spent = null;

    #[Group('mysql')]
    public function test_issue_and_consume_once_on_mysql(): void
    {
        $this->onMysql();
        $this->test_issue_and_consume_once();
    }

    #[Group('mysql')]
    public function test_expired_token_rejected_on_mysql(): void
    {
        $this->onMysql();
        $this->test_expired_token_rejected();
    }

    #[Group('mysql')]
    public function test_wrong_purpose_rejected_on_mysql(): void
    {
        $this->onMysql();
        $this->test_wrong_purpose_rejected();
    }

    #[Group('mysql')]
    public function test_token_stored_only_as_hash_on_mysql(): void
    {
        $this->onMysql();
        $this->test_token_stored_only_as_hash();
    }

    #[Group('mysql')]
    public function test_revoke_all_on_mysql(): void
    {
        $this->onMysql();
        $this->test_revoke_all();
    }

    #[Group('mysql')]
    public function test_prune_deletes_expired_rows_on_mysql(): void
    {
        $this->onMysql();
        $this->test_prune_deletes_expired_rows();
    }

    #[Group('mysql')]
    public function test_consume_is_decided_by_the_delete_on_mysql(): void
    {
        $this->onMysql();
        self::$spent = null;
        $this->test_consume_is_decided_by_the_delete();
    }

    /**
     * Four requests consume one token at once, in separate processes on their own
     * connections, each pausing between reading the row and deleting it: the DELETE
     * lets exactly one of them have the subject.
     */
    #[Group('mysql')]
    #[\PHPUnit\Framework\Attributes\RequiresPhpExtension('pcntl')]
    #[\PHPUnit\Framework\Attributes\RequiresPhpExtension('posix')]
    public function test_concurrent_consumers_get_the_subject_once_on_mysql(): void
    {
        $this->onMysql();
        $token = $this->tokens()->issue('confirm', 'user-1', 3600);
        $results = sys_get_temp_dir() . '/preflow_tokens_race_' . uniqid();
        $this->lockDirs[] = $results;
        mkdir($results);

        $children = [];
        for ($i = 0; $i < 4; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('pcntl_fork() failed');
            }
            if ($pid === 0) {
                try {
                    $dm = new DataManager(['default' => new MysqlDriver(MysqlTestDatabase::reconnect())]);
                    $slow = function (): int {
                        usleep(200_000); // between reading the row and deleting it

                        return $this->now;
                    };
                    $result = (new Tokens($dm, 'default', $slow))->consume($token, 'confirm');
                    file_put_contents("{$results}/{$i}.json", json_encode($result));
                } finally {
                    // Even after a throw: no shutdown, PHPUnit's handlers and the inherited connection belong to the parent.
                    posix_kill(posix_getpid(), SIGKILL);
                }
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $got = array_map(static fn (string $f): mixed => json_decode((string) file_get_contents($f), true), glob($results . '/*.json') ?: []);
        $this->assertCount(4, $got, 'every child reported');
        $this->assertSame(['user-1'], array_values(array_filter($got)), 'exactly one consumer gets the subject');
        $this->assertSame([], $this->dataManager()->raw('SELECT id FROM auth_tokens'));
    }

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
        $this->assertNotEmpty($held);
        $this->assertNotContains(false, $held, 'the lock is held while consume() reads and deletes');
        $this->assertFileDoesNotExist($lockFile, 'the lock file is removed afterwards');
        $this->assertSame([], glob($lockDir . '/*') ?: []);
    }

    public function test_unknown_token_creates_no_lock_file_on_json(): void
    {
        $lockDir = sys_get_temp_dir() . '/preflow_tokens_locks_' . uniqid();
        $this->lockDirs[] = $lockDir;
        $t = new Tokens($this->dataManager(), 'json', fn (): int => $this->now, $lockDir);
        $t->issue('confirm', 'user-1', 3600);
        $before = is_dir($lockDir) ? (glob($lockDir . '/*') ?: []) : [];

        $this->assertNull($t->consume(rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='), 'confirm'));
        $this->assertSame($before, is_dir($lockDir) ? (glob($lockDir . '/*') ?: []) : []);
    }

    /**
     * Three requests on one token, in separate processes as on a server: H probes it with
     * the wrong purpose; B starts consuming while H holds the lock; C arrives once H is
     * done. B and C each pause between reading the row and deleting it, so if both got
     * into the critical section they would both return the subject.
     */
    #[\PHPUnit\Framework\Attributes\RequiresPhpExtension('pcntl')]
    #[\PHPUnit\Framework\Attributes\RequiresPhpExtension('posix')]
    public function test_wrong_purpose_probe_cannot_let_two_consumers_through(): void
    {
        $lockDir = sys_get_temp_dir() . '/preflow_tokens_locks_' . uniqid();
        $this->lockDirs[] = $lockDir;
        $resultFile = $lockDir . '-b.json';
        $slow = function (): int {
            usleep(300_000); // between reading the row and deleting it

            return $this->now;
        };
        $token = (new Tokens($this->dataManager(), 'json', fn (): int => $this->now, $lockDir))->issue('confirm', 'user-1', 3600);

        $child = null;
        $h = new Tokens($this->dataManager(), 'json', function () use (&$child, $token, $slow, $lockDir, $resultFile): int {
            // Inside H's critical section: B starts and waits for the lock.
            $child = pcntl_fork();
            if ($child === -1) {
                $this->fail('pcntl_fork() failed');
            }
            if ($child === 0) {
                try {
                    $result = (new Tokens($this->dataManager(), 'json', $slow, $lockDir))->consume($token, 'confirm');
                    file_put_contents($resultFile, json_encode($result));
                } finally {
                    posix_kill(posix_getpid(), SIGKILL);
                }
            }
            usleep(200_000);

            return $this->now;
        }, $lockDir);

        $this->assertNull($h->consume($token, 'reset'));
        $c = (new Tokens($this->dataManager(), 'json', $slow, $lockDir))->consume($token, 'confirm');
        pcntl_waitpid($child, $status);
        $b = json_decode((string) file_get_contents($resultFile), true);
        @unlink($resultFile);

        $this->assertSame(['user-1'], array_values(array_filter([$b, $c])), 'exactly one consumer gets the subject');
    }
}
