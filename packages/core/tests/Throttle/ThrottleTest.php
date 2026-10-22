<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Throttle;

use Nyholm\Psr7\ServerRequest;
use Preflow\Core\Throttle\Throttle;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\Migration\Migration;
use Preflow\Data\Migration\Schema;
use Preflow\Testing\DataTestCase;

final class ThrottleTest extends DataTestCase
{
    private int $now = 1_000_000;

    private function throttle(string $storage = 'default'): Throttle
    {
        return new Throttle($this->dataManager(), 'secret', $storage, fn (): int => $this->now);
    }

    private function migrate(): void
    {
        /** @var Migration $migration */
        $migration = require dirname(__DIR__, 2) . '/migrations/2026_10_22_000000_preflow_throttle.php';
        $migration->up(new Schema($this->getPdo()));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrate();
    }

    public function test_allows_until_max_then_blocks(): void
    {
        $t = $this->throttle();
        for ($i = 0; $i < 3; $i++) {
            $this->assertFalse($t->tooMany('code', 'c1', 3, 60));
            $t->hit('code', 'c1', 60);
        }
        $this->assertTrue($t->tooMany('code', 'c1', 3, 60));
        $this->assertFalse($t->tooMany('code', 'c2', 3, 60));
    }

    public function test_window_expiry_resets(): void
    {
        $t = $this->throttle();
        $t->hit('code', 'c1', 60);
        $t->hit('code', 'c1', 60);
        $this->assertTrue($t->tooMany('code', 'c1', 2, 60));

        $this->now += 61;
        $this->assertFalse($t->tooMany('code', 'c1', 2, 60));
        $t->hit('code', 'c1', 60);
        $this->assertFalse($t->tooMany('code', 'c1', 2, 60));
        $t->hit('code', 'c1', 60);
        $this->assertTrue($t->tooMany('code', 'c1', 2, 60));
    }

    public function test_clear_resets(): void
    {
        $t = $this->throttle();
        $t->hit('code', 'c1', 60);
        $this->assertTrue($t->tooMany('code', 'c1', 1, 60));
        $t->clear('code', 'c1');
        $this->assertFalse($t->tooMany('code', 'c1', 1, 60));
        $t->clear('code', 'never-hit');
    }

    public function test_purposes_are_separate(): void
    {
        $t = $this->throttle();
        $t->hit('code', 'c1', 60);
        $this->assertTrue($t->tooMany('code', 'c1', 1, 60));
        $this->assertFalse($t->tooMany('login', 'c1', 1, 60));
    }

    public function test_client_hash_has_no_raw_ip(): void
    {
        $request = new ServerRequest('GET', '/', [], null, '1.1', ['REMOTE_ADDR' => '203.0.113.7']);
        $client = $this->throttle()->client($request);
        $this->assertStringNotContainsString('203.0.113.7', $client);
        $this->assertSame(hash_hmac('sha256', '203.0.113.7', 'secret|' . gmdate('Y-m-d')), $client);
        $forwarded = $request->withHeader('X-Forwarded-For', '198.51.100.1');
        $this->assertSame($client, $this->throttle()->client($forwarded));
    }

    public function test_ipv6_clients_grouped_by_64(): void
    {
        $t = $this->throttle();
        $client = static fn (string $ip): string => $t->client(new ServerRequest('GET', '/', [], null, '1.1', ['REMOTE_ADDR' => $ip]));

        // One /64 (a single subscriber's usual allocation) is one client, however written.
        $a = $client('2001:db8:1:2::1');
        $this->assertSame($a, $client('2001:0db8:0001:0002:ffff:ffff:ffff:fffe'));
        $this->assertSame($a, $client('2001:DB8:1:2:abcd::9'));
        $this->assertSame(hash_hmac('sha256', '2001:db8:1:2::/64', 'secret|' . gmdate('Y-m-d')), $a);
        $this->assertNotSame($a, $client('2001:db8:1:3::1'));

        // IPv4 unchanged, also when written IPv4-mapped.
        $this->assertSame(hash_hmac('sha256', '203.0.113.7', 'secret|' . gmdate('Y-m-d')), $client('203.0.113.7'));
        $this->assertSame($client('203.0.113.7'), $client('::ffff:203.0.113.7'));
        $this->assertNotSame($client('203.0.113.7'), $client('203.0.113.8'));
    }

    public function test_client_refuses_default_key(): void
    {
        $request = new ServerRequest('GET', '/', [], null, '1.1', ['REMOTE_ADDR' => '203.0.113.7']);
        foreach (['preflow-default-key-change-me!!', ''] as $key) {
            $throttle = new Throttle($this->dataManager(), $key, 'default', fn (): int => $this->now);
            $message = null;
            try {
                $throttle->client($request);
            } catch (\RuntimeException $e) {
                $message = $e->getMessage();
            }
            $this->assertSame('Throttle needs APP_KEY (app.key) to be set; the default key would make client hashes reversible.', $message, "key \"{$key}\"");
            // The rest works on a client string.
            $throttle->hit('login', 'some-client', 60);
            $this->assertTrue($throttle->tooMany('login', 'some-client', 1, 60));
            $throttle->clear('login', 'some-client');
            $this->assertFalse($throttle->tooMany('login', 'some-client', 1, 60));
        }
    }

    public function test_missing_remote_addr_is_unknown_client(): void
    {
        $t = $this->throttle();
        $a = $t->client(new ServerRequest('GET', '/'));
        $b = $t->client(new ServerRequest('GET', '/other'));
        $this->assertSame($a, $b);
        $this->assertSame(hash_hmac('sha256', 'unknown', 'secret|' . gmdate('Y-m-d')), $a);
    }

    public function test_works_on_sqlite_and_json(): void
    {
        foreach (['sqlite', 'json'] as $storage) {
            $t = $this->throttle($storage);
            $t->hit('code', 'c1', 60);
            $t->hit('code', 'c1', 60);
            $this->assertTrue($t->tooMany('code', 'c1', 2, 60), $storage);
            $this->assertFalse($t->tooMany('code', 'c1', 3, 60), $storage);
            $this->now += 61;
            $this->assertFalse($t->tooMany('code', 'c1', 2, 60), $storage);
            $t->hit('code', 'c1', 60);
            $this->assertTrue($t->tooMany('code', 'c1', 1, 60), $storage);
            $t->clear('code', 'c1');
            $this->assertFalse($t->tooMany('code', 'c1', 1, 60), $storage);
            $this->now -= 61;
        }
    }

    private function oldRow(string $id, int $start): void
    {
        $this->dataManager()->saveType(new \Preflow\Data\DynamicRecord(
            (new \ReflectionProperty(Throttle::class, 'type'))->getValue($this->throttle),
            ['id' => $id, 'purpose' => 'code', 'hits' => 1, 'window_start' => $start],
        ), validate: false);
    }

    private function rowExists(string $id): bool
    {
        return $this->dataManager()->findIn(
            (new \ReflectionProperty(Throttle::class, 'type'))->getValue($this->throttle),
            $id,
        ) !== null;
    }

    private Throttle $throttle;

    public function test_prune_removes_rows_older_than_cutoff(): void
    {
        $this->throttle = $t = $this->throttle();
        $this->oldRow('old', $this->now - 3 * 86400);
        $this->oldRow('recent', $this->now - 3600);

        $this->assertSame(1, $t->prune());
        $this->assertFalse($this->rowExists('old'));
        $this->assertTrue($this->rowExists('recent'));
    }

    public function test_new_row_triggers_prune_once(): void
    {
        $this->throttle = $t = $this->throttle();
        $this->oldRow('old1', $this->now - 2 * 86400 - 10);
        $t->hit('code', 'c1', 60);
        $this->assertFalse($this->rowExists('old1'));

        $this->oldRow('old2', $this->now - 2 * 86400 - 10);
        $t->hit('code', 'c2', 60);
        $this->assertTrue($this->rowExists('old2'));
    }

    public function test_window_expires_exactly_at_boundary(): void
    {
        $t = $this->throttle();
        $t->hit('code', 'c1', 60);
        $this->now += 59;
        $this->assertTrue($t->tooMany('code', 'c1', 1, 60));
        $this->now += 1;
        $this->assertFalse($t->tooMany('code', 'c1', 1, 60));
    }

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_dir($path)) {
                array_map(unlink(...), glob($path . '/*') ?: []);
                rmdir($path);
            } elseif (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    /**
     * Two Throttles on two DataManagers (two connections) over one SQLite file, as two
     * requests would be. $hook runs once, inside $a's next hit(), at its first clock read.
     *
     * @return array{Throttle, Throttle, \Closure(\Closure): void, DataManager}
     */
    private function sharedFile(): array
    {
        $file = tempnam(sys_get_temp_dir(), 'preflow_throttle_');
        $this->tempFiles[] = $file;
        $dm = static fn (): DataManager => new DataManager(['default' => new SqliteDriver(new \PDO('sqlite:' . $file))]);
        $dmA = $dm();
        /** @var Migration $migration */
        $migration = require dirname(__DIR__, 2) . '/migrations/2026_10_22_000000_preflow_throttle.php';
        $migration->up(new Schema($dmA->driver('default')->pdo()));

        $hook = null;
        $a = new Throttle($dmA, 'secret', 'default', function () use (&$hook): int {
            if ($hook !== null) {
                [$run, $hook] = [$hook, null];
                $run();
            }

            return $this->now;
        });
        $b = new Throttle($dm(), 'secret', 'default', fn (): int => $this->now);

        return [$a, $b, static function (\Closure $run) use (&$hook): void { $hook = $run; }, $dmA];
    }

    /** @return array{hits: int, window_start: int}|null */
    private function storedRow(DataManager $dm, string $purpose, string $client): ?array
    {
        $rows = $dm->raw('SELECT hits, window_start FROM preflow_throttle WHERE id = ?', [hash('sha256', $purpose . '|' . $client)]);

        return $rows === [] ? null : ['hits' => (int) $rows[0]['hits'], 'window_start' => (int) $rows[0]['window_start']];
    }

    public function test_hit_is_atomic_under_interleaving(): void
    {
        [$a, $b, $between, $dm] = $this->sharedFile();

        // Both requests checked the limit before either counted its attempt.
        $this->assertFalse($a->tooMany('login', 'c1', 5, 60));
        $this->assertFalse($b->tooMany('login', 'c1', 5, 60));
        // $b's hit lands between $a's read and $a's write.
        $between(static fn () => $b->hit('login', 'c1', 60));
        $a->hit('login', 'c1', 60);
        $this->assertSame(2, $this->storedRow($dm, 'login', 'c1')['hits']);

        $between(static fn () => $b->hit('login', 'c1', 60));
        $a->hit('login', 'c1', 60);
        $this->assertSame(4, $this->storedRow($dm, 'login', 'c1')['hits']);
        $this->assertFalse($a->tooMany('login', 'c1', 5, 60));
        $this->assertTrue($b->tooMany('login', 'c1', 4, 60));
    }

    public function test_window_reset_in_upsert(): void
    {
        [$a, $b, $between, $dm] = $this->sharedFile();
        $start = $this->now;
        $a->hit('login', 'c1', 60);
        $a->hit('login', 'c1', 60);
        $this->assertSame(['hits' => 2, 'window_start' => $start], $this->storedRow($dm, 'login', 'c1'));

        // Still inside the window one second before it ends.
        $this->now += 59;
        $a->hit('login', 'c1', 60);
        $this->assertSame(['hits' => 3, 'window_start' => $start], $this->storedRow($dm, 'login', 'c1'));

        // Expired: the row starts over at now.
        $this->now += 1;
        $a->hit('login', 'c1', 60);
        $this->assertSame(['hits' => 1, 'window_start' => $this->now], $this->storedRow($dm, 'login', 'c1'));

        // Expired again, and $b resets the window between $a's read and write: $a's
        // hit counts in the new window instead of resetting it a second time.
        $this->now += 60;
        $between(static fn () => $b->hit('login', 'c1', 60));
        $a->hit('login', 'c1', 60);
        $this->assertSame(['hits' => 2, 'window_start' => $this->now], $this->storedRow($dm, 'login', 'c1'));
    }

    public function test_json_hit_locks(): void
    {
        $lockDir = sys_get_temp_dir() . '/preflow_throttle_locks_' . uniqid();
        $this->tempFiles[] = $lockDir;
        $lockFile = $lockDir . '/throttle-' . hash('sha256', 'login|c1') . '.lock';
        $heldDuringHit = [];
        $t = new Throttle($this->dataManager(), 'secret', 'json', function () use ($lockFile, &$heldDuringHit): int {
            if (is_file($lockFile)) {
                $probe = fopen($lockFile, 'c');
                $heldDuringHit[] = !flock($probe, LOCK_EX | LOCK_NB);
                fclose($probe);
            }

            return $this->now;
        }, $lockDir);

        $t->hit('login', 'c1', 60);
        $t->hit('login', 'c1', 60);
        $t->hit('login', 'c1', 60);

        $this->assertFileExists($lockFile);
        $this->assertNotEmpty($heldDuringHit);
        $this->assertNotContains(false, $heldDuringHit, 'the lock is held while hit() reads and writes');
        // Released afterwards.
        $probe = fopen($lockFile, 'c');
        $this->assertTrue(flock($probe, LOCK_EX | LOCK_NB));
        flock($probe, LOCK_UN);
        fclose($probe);

        $this->assertTrue($t->tooMany('login', 'c1', 3, 60));
        $this->assertFalse($t->tooMany('login', 'c1', 4, 60));
    }
}
