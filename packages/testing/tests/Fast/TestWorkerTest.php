<?php

declare(strict_types=1);

namespace Preflow\Testing\Tests\Fast;

use PHPUnit\Framework\TestCase;
use Preflow\Testing\Fast\TestWorker;

final class TestWorkerTest extends TestCase
{
    private string|false $token = false;

    protected function setUp(): void
    {
        $this->token = getenv('TEST_TOKEN');
    }

    protected function tearDown(): void
    {
        // Under paratest the real token must survive for the worker's later tests.
        putenv($this->token === false ? 'TEST_TOKEN' : 'TEST_TOKEN=' . $this->token);
        foreach (['x', 'x_3'] as $dir) {
            @rmdir(sys_get_temp_dir() . '/preflow_worker_test_' . $dir);
        }
    }

    public function test_without_a_token_names_are_unchanged(): void
    {
        putenv('TEST_TOKEN');
        $this->assertNull(TestWorker::token());
        $this->assertSame('', TestWorker::suffix());
        $this->assertSame('x', TestWorker::name('x'));
        $dir = TestWorker::dir('preflow_worker_test_x');
        $this->assertStringEndsWith('/preflow_worker_test_x', $dir);
        $this->assertDirectoryExists($dir);
    }

    public function test_a_worker_token_suffixes_names_and_dirs(): void
    {
        putenv('TEST_TOKEN=3');
        $this->assertSame(3, TestWorker::token());
        $this->assertSame('_3', TestWorker::suffix());
        $this->assertSame('x_3', TestWorker::name('x'));
        $dir = TestWorker::dir('preflow_worker_test_x');
        $this->assertStringEndsWith('/preflow_worker_test_x_3', $dir);
        $this->assertDirectoryExists($dir);
    }

    public function test_a_non_numeric_token_is_ignored(): void
    {
        putenv('TEST_TOKEN=abc');
        $this->assertNull(TestWorker::token());
        $this->assertSame('x', TestWorker::name('x'));
    }

    public function test_create_mysql_database_names_the_grant_on_failure(): void
    {
        $server = new class ('sqlite::memory:') extends \PDO {
            public function exec(string $statement): int|false
            {
                throw new \PDOException('Access denied');
            }
        };

        try {
            TestWorker::createMysqlDatabase($server, 'holz_test_3', 'holz');
            $this->fail('expected an exception');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('GRANT ALL PRIVILEGES ON `holz\_test\_%`.* TO \'holz\'@\'localhost\';', $e->getMessage());
            $this->assertStringContainsString('Access denied', $e->getMessage());
        }
    }
}
