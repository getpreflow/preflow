<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Throttle;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Preflow\Core\Throttle\Throttle;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\Dialect;
use Preflow\Data\Driver\PdoDriver;
use Preflow\Data\Driver\QueryCompiler;
use Preflow\Data\Driver\SqliteDialect;
use Preflow\Data\Driver\MysqlDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\Migration\Migration;
use Preflow\Data\Migration\Schema;
use Preflow\Testing\DataTestCase;
use Preflow\Testing\MysqlTestDatabase;
use Preflow\Testing\PlainDriver;
use Preflow\Data\UniqueViolation;

final class ThrottleTest extends DataTestCase
{
    private int $now = 1_000_000;

    private function throttle(string $storage = 'default'): Throttle
    {
        return new Throttle($this->dataManager(), 'secret', $storage, fn (): int => $this->now);
    }

    private function migrate(?\PDO $pdo = null): void
    {
        /** @var Migration $migration */
        $migration = require dirname(__DIR__, 2) . '/migrations/2026_10_22_000000_preflow_throttle.php';
        $migration->up(new Schema($pdo ?? $this->getPdo()));
    }

    /** The rest of the test on MySQL: 'default' is a migrated MySQL storage. */
    private function onMysql(): void
    {
        $this->migrate($this->useMysql()->pdo());
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
        foreach ($this->usesMysql() ? ['mysql'] : ['sqlite', 'json'] as $storage) {
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

    /** A third-party SQL database: the hit is one statement its Dialect writes, not MySQL's or SQLite's. */
    public function test_a_third_party_dialect_writes_the_counting_statement(): void
    {
        $driver = new ThirdPartyDriver($this->getPdo());
        $t = new Throttle(new DataManager(['default' => $driver]), 'secret', 'default', fn (): int => $this->now);

        $this->countsAndRestarts($t);
        $this->assertContains('insertOrUpdateSql', $driver->dialect()->asked);
        $this->assertNotEmpty(array_filter($driver->executed, static fn (string $sql): bool => str_contains($sql, 'ON CONFLICT')));
    }

    /**
     * PostgreSQL rejects a bare column name in ON CONFLICT DO UPDATE as ambiguous (the table's or
     * EXCLUDED's), and a dialect cannot repair the caller's expressions: every column the
     * expressions read is qualified with the table.
     */
    public function test_the_counting_expressions_read_table_qualified_columns(): void
    {
        $driver = new ThirdPartyDriver($this->getPdo());
        (new Throttle(new DataManager(['default' => $driver]), 'secret', 'default', fn (): int => $this->now))->hit('code', 'c1', 60);

        $sets = $driver->dialect()->sets;
        $this->assertCount(1, $sets);
        foreach ($sets[0] as $column => $expression) {
            $this->assertMatchesRegularExpression('/"preflow_throttle"\."[a-z_]+"/', $expression, $column);
            $this->assertStringNotContainsString('"', (string) preg_replace('/"preflow_throttle"\."[a-z_]+"/', '', $expression), "{$column}: a bare column in {$expression}");
        }
    }

    /** A dialect without a single insert-or-update statement counts under the driver's exclusive lock instead. */
    public function test_a_dialect_without_insert_or_update_counts_under_the_lock(): void
    {
        $driver = new ThirdPartyDriver($this->getPdo(), new ThirdPartyDialect(insertOrUpdate: false));
        $t = new Throttle(new DataManager(['default' => $driver]), 'secret', 'default', fn (): int => $this->now);

        $this->countsAndRestarts($t);
        $this->assertContains('insertOrUpdateSql', $driver->dialect()->asked);
        $this->assertSame([], array_filter($driver->executed, static fn (string $sql): bool => str_starts_with($sql, 'INSERT')));
    }

    private function countsAndRestarts(Throttle $t): void
    {
        $t->hit('code', 'c1', 60);
        $t->hit('code', 'c1', 60);
        $this->assertTrue($t->tooMany('code', 'c1', 2, 60));
        $this->assertFalse($t->tooMany('code', 'c1', 3, 60));
        $this->now += 60;
        $this->assertFalse($t->tooMany('code', 'c1', 1, 60));
        $t->hit('code', 'c1', 60);
        $this->assertTrue($t->tooMany('code', 'c1', 1, 60));
        $this->assertFalse($t->tooMany('code', 'c1', 2, 60), 'the window restarted at one hit');
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

    /** @return array<string, array{string}> */
    public static function storages(): array
    {
        return ['json' => ['json'], 'sqlite' => ['sqlite']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('storages')]
    public function test_for_person_is_stable_and_hides_the_id(string $storage = 'sqlite'): void
    {
        $t = $this->throttle($storage);
        $a = $t->forPerson('u1');
        $this->now += 86400;
        $this->assertSame($a, $t->forPerson('u1'), 'no daily rotation: an hour\'s window must not restart at midnight');
        $this->assertSame(hash_hmac('sha256', 'person|u1', 'secret'), $a);
        $this->assertNotSame($a, $t->forPerson('u2'));
        $this->assertNotSame($a, $t->client(new ServerRequest('GET', '/', [], null, '1.1', ['REMOTE_ADDR' => 'u1'])));
        $this->assertStringNotContainsString('u1', $a);

        // Counted under the hash, never the raw id.
        $t->hit('ai.draft', $a, 3600);
        $this->assertTrue($t->tooMany('ai.draft', $a, 1, 3600), $storage);

        foreach ([Throttle::DEFAULT_KEY, ''] as $key) {
            $message = null;
            try {
                (new Throttle($this->dataManager(), $key, $storage, fn (): int => $this->now))->forPerson('u1');
            } catch (\RuntimeException $e) {
                $message = $e->getMessage();
            }
            $this->assertSame('Throttle needs APP_KEY (app.key) to be set; the default key would make client hashes reversible.', $message, "key \"{$key}\"");
        }
    }

    public function test_has_key_is_false_for_an_empty_or_the_default_key(): void
    {
        $this->assertTrue($this->throttle()->hasKey());
        $this->assertFalse((new Throttle($this->dataManager(), Throttle::DEFAULT_KEY))->hasKey());
        $this->assertFalse((new Throttle($this->dataManager(), ''))->hasKey());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('storages')]
    public function test_hit_returns_the_count_in_the_window(string $storage = 'sqlite'): void
    {
        $t = $this->throttle($storage);
        $this->assertSame(1, $t->hit('code', 'c1', 60), $storage);
        $this->assertSame(2, $t->hit('code', 'c1', 60), $storage);
        $this->assertSame(3, $t->hit('code', 'c1', 60), $storage);
        $this->assertSame(1, $t->hit('code', 'c2', 60), 'another client counts apart');
        $this->now += 60;
        $this->assertSame(1, $t->hit('code', 'c1', 60), 'after the window: 1 again');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('storages')]
    public function test_retry_after_counts_down_to_the_window_end(string $storage = 'sqlite'): void
    {
        $t = $this->throttle($storage);
        $this->assertSame(0, $t->retryAfter('ai.draft', 'p1', 3600), 'no row');

        $this->now = 100;
        $t->hit('ai.draft', 'p1', 3600);
        $this->now = 1000;
        $this->assertSame(2700, $t->retryAfter('ai.draft', 'p1', 3600), $storage);
        $this->now = 3699;
        $this->assertSame(1, $t->retryAfter('ai.draft', 'p1', 3600));
        $this->now = 3700;
        $this->assertSame(0, $t->retryAfter('ai.draft', 'p1', 3600), 'the window has ended');
        $this->assertSame(0, $t->retryAfter('ai.translate', 'p1', 3600), 'another purpose has no row');
    }

    #[Group('mysql')]
    public function test_for_person_is_stable_and_hides_the_id_on_mysql(): void
    {
        $this->onMysql();
        $this->test_for_person_is_stable_and_hides_the_id('mysql');
    }

    #[Group('mysql')]
    public function test_hit_returns_the_count_in_the_window_on_mysql(): void
    {
        $this->onMysql();
        $this->test_hit_returns_the_count_in_the_window('mysql');
    }

    #[Group('mysql')]
    public function test_retry_after_counts_down_to_the_window_end_on_mysql(): void
    {
        $this->onMysql();
        $this->test_retry_after_counts_down_to_the_window_end('mysql');
    }

    #[Group('mysql')]
    public function test_allows_until_max_then_blocks_on_mysql(): void
    {
        $this->onMysql();
        $this->test_allows_until_max_then_blocks();
    }

    #[Group('mysql')]
    public function test_window_expiry_resets_on_mysql(): void
    {
        $this->onMysql();
        $this->test_window_expiry_resets();
    }

    #[Group('mysql')]
    public function test_clear_resets_on_mysql(): void
    {
        $this->onMysql();
        $this->test_clear_resets();
    }

    #[Group('mysql')]
    public function test_purposes_are_separate_on_mysql(): void
    {
        $this->onMysql();
        $this->test_purposes_are_separate();
    }

    #[Group('mysql')]
    public function test_works_on_mysql(): void
    {
        $this->onMysql();
        $this->test_works_on_sqlite_and_json();
    }

    #[Group('mysql')]
    public function test_prune_removes_rows_older_than_cutoff_on_mysql(): void
    {
        $this->onMysql();
        $this->test_prune_removes_rows_older_than_cutoff();
    }

    #[Group('mysql')]
    public function test_new_row_triggers_prune_once_on_mysql(): void
    {
        $this->onMysql();
        $this->test_new_row_triggers_prune_once();
    }

    #[Group('mysql')]
    public function test_window_expires_exactly_at_boundary_on_mysql(): void
    {
        $this->onMysql();
        $this->test_window_expires_exactly_at_boundary();
    }

    /** Four processes, each on its own connection, hit one key 25 times: the increment loses none. */
    #[Group('mysql')]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function test_concurrent_hits_are_all_counted_on_mysql(): void
    {
        $this->onMysql();
        $this->hitConcurrently('default', static fn (): DataManager => new DataManager(['default' => new MysqlDriver(MysqlTestDatabase::reconnect())]));

        $this->assertSame(['hits' => 100, 'window_start' => $this->now], $this->storedRow($this->dataManager(), 'login', 'c1'));
    }

    /**
     * Four forked processes hit login/c1 25 times each, through the DataManager $dm makes in
     * the child (its own connection).
     *
     * @param \Closure(): DataManager $dm
     */
    private function hitConcurrently(string $storage, \Closure $dm): void
    {
        $children = [];
        for ($i = 0; $i < 4; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('pcntl_fork() failed');
            }
            if ($pid === 0) {
                try {
                    $t = new Throttle($dm(), 'secret', $storage, fn (): int => $this->now);
                    for ($n = 0; $n < 25; $n++) {
                        $t->hit('login', 'c1', 60);
                    }
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

    /** Four processes, each with its own JSON driver on the data folder, hit one key 25 times: the type's lock loses none. */
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function test_concurrent_hits_are_all_counted_on_json(): void
    {
        $this->hitConcurrently('json', fn (): DataManager => new DataManager(['json' => $this->getJsonDriver()]));

        $t = $this->throttle('json');
        $this->assertTrue($t->tooMany('login', 'c1', 100, 60));
        $this->assertFalse($t->tooMany('login', 'c1', 101, 60));
    }

    /** A driver with none of the capabilities: DataManager reads, adds and writes (not atomic, but counted). */
    public function test_throttle_counts_through_a_driver_without_atomic_counters(): void
    {
        $t = new Throttle(new DataManager(['default' => new PlainDriver($this->getSqliteDriver())]), 'secret', 'default', fn (): int => $this->now);

        $t->hit('code', 'c1', 60);
        $t->hit('code', 'c1', 60);
        $t->hit('code', 'c1', 60);
        $this->assertTrue($t->tooMany('code', 'c1', 3, 60));
        $this->assertFalse($t->tooMany('code', 'c1', 4, 60));

        $this->now += 60;
        $t->hit('code', 'c1', 60);
        $this->assertTrue($t->tooMany('code', 'c1', 1, 60));
        $this->assertFalse($t->tooMany('code', 'c1', 2, 60), 'the window restarted at one hit');
    }

    /** The lock directory is ignored since 0.78: the JSON driver's own type lock guards the count. */
    public function test_throttle_no_longer_writes_lock_files(): void
    {
        $lockDir = sys_get_temp_dir() . '/preflow_throttle_locks_' . uniqid();
        mkdir($lockDir);
        $this->tempFiles[] = $lockDir;
        $t = new Throttle($this->dataManager(), 'secret', 'json', fn (): int => $this->now, $lockDir);

        $t->hit('login', 'c1', 60);
        $t->hit('login', 'c1', 60);

        $this->assertSame([], glob($lockDir . '/*') ?: []);
        $this->assertTrue($t->tooMany('login', 'c1', 2, 60));
    }
}


/**
 * A third party's SQL dialect (PostgreSQL, say), run on SQLite: it delegates to SQLite's, records
 * which methods were asked, and can say it has no single insert-or-update statement.
 */
final class ThirdPartyDialect implements Dialect
{
    /** @var list<string> */
    public array $asked = [];

    private SqliteDialect $inner;

    public function __construct(private readonly bool $insertOrUpdate = true)
    {
        $this->inner = new SqliteDialect();
    }

    /** @var list<array<string, string>> the $set of each insertOrUpdateSql() */
    public array $sets = [];

    public function insertOrUpdateSql(string $table, array $columns, string $idField, array $set): ?string
    {
        $this->asked[] = 'insertOrUpdateSql';
        $this->sets[] = $set;

        return $this->insertOrUpdate ? $this->inner->insertOrUpdateSql($table, $columns, $idField, $set) : null;
    }

    public function wholeNumber(string $expression): string
    {
        $this->asked[] = 'wholeNumber';

        return $this->inner->wholeNumber($expression);
    }

    public function quoteIdentifier(string $name): string
    {
        return $this->inner->quoteIdentifier($name);
    }

    public function updateSql(string $table, array $columns, string $idField): string
    {
        return $this->inner->updateSql($table, $columns, $idField);
    }

    public function insertSql(string $table, array $columns): string
    {
        return $this->inner->insertSql($table, $columns);
    }

    public function upsertSql(string $table, array $columns, string $idField): ?string
    {
        return $this->inner->upsertSql($table, $columns, $idField);
    }

    public function isPrimaryKeyViolation(\PDOException $e, string $table, string $idField): bool
    {
        return $this->inner->isPrimaryKeyViolation($e, $table, $idField);
    }

    public function uniqueViolation(\PDOException $e, string $table, string $idField): ?UniqueViolation
    {
        return $this->inner->uniqueViolation($e, $table, $idField);
    }

    public function lockingRead(): string
    {
        return $this->inner->lockingRead();
    }

    public function localizedColumn(string $quotedColumn, string $locale, bool $isDefault): string
    {
        return $this->inner->localizedColumn($quotedColumn, $locale, $isDefault);
    }

    public function numericValue(string $expression): string
    {
        return $this->inner->numericValue($expression);
    }

    public function exactText(string $expression): string
    {
        return $this->inner->exactText($expression);
    }

    public function likeOperator(): string
    {
        return $this->inner->likeOperator();
    }

    public function numericOrder(string $expression): string
    {
        return $this->inner->numericOrder($expression);
    }

    public function missingColumn(\PDOException $e): ?string
    {
        return $this->inner->missingColumn($e);
    }

    public function inList(string $quotedColumn, array $ids, array &$bindings): string
    {
        return $this->inner->inList($quotedColumn, $ids, $bindings);
    }

    public function jsonArrayHasAny(string $quotedColumn, array $ids, array &$bindings): string
    {
        return $this->inner->jsonArrayHasAny($quotedColumn, $ids, $bindings);
    }
}

/** A third party's PdoDriver with its own dialect; it records the SQL it is asked to execute. */
final class ThirdPartyDriver extends PdoDriver
{
    /** @var list<string> */
    public array $executed = [];

    public function __construct(\PDO|\Closure $pdo, ?Dialect $dialect = null)
    {
        $dialect ??= new ThirdPartyDialect();
        parent::__construct($pdo, $dialect, new QueryCompiler($dialect));
    }

    /** One test process writes: running $fn is enough (a real driver takes its database's lock). */
    public function exclusive(string $type, \Closure $fn): mixed
    {
        return $fn();
    }

    public function execute(string $sql, array $bindings = []): int
    {
        $this->executed[] = $sql;

        return parent::execute($sql, $bindings);
    }
}
