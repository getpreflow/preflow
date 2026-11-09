<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\Locales;
use Preflow\Data\Tests\Fixtures\ModelBridge\Ticket;
use Preflow\Data\TypeRegistry;

/** A class marked as a type whose key the registry does not know never falls back to the plain typed-model path. */
final class UnknownClassTypeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/Fixtures/ModelBridge/Ticket.php';
    }

    private function driver(): SqliteDriver
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE ticket (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT)');

        return new SqliteDriver($pdo);
    }

    public function test_a_registry_without_the_key_is_an_error(): void
    {
        $dir = sys_get_temp_dir() . '/unknown_type_' . bin2hex(random_bytes(4));
        mkdir($dir);
        $dm = new DataManager(['default' => $this->driver()], 'default', new TypeRegistry($dir, new Locales(['en'], 'en')));
        $ticket = new Ticket();
        $ticket->title = 'a';

        try {
            $dm->save($ticket);
            $this->fail('expected a LogicException');
        } catch (\LogicException $e) {
            $this->assertStringContainsString(Ticket::class, $e->getMessage());
            $this->assertStringContainsString('"ticket"', $e->getMessage());
            $this->assertStringContainsString('data.model_paths', $e->getMessage());
        } finally {
            @rmdir($dir);
        }
    }

    public function test_no_registry_is_an_error_on_reads_too(): void
    {
        $dm = new DataManager(['default' => $this->driver()]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('data.model_paths');
        $dm->find(Ticket::class, 1);
    }
}
