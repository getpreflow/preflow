<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

require_once __DIR__ . '/AggregateScenarios.php';

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\SqliteDriver;

/** The aggregate scenarios on SQLite. */
final class AggregateSqliteScenariosTest extends TestCase
{
    use AggregateScenarios;

    protected function setUp(): void
    {
        $this->dm = new DataManager(['sqlite' => new SqliteDriver(new \PDO('sqlite::memory:'))], 'sqlite');
    }

    protected function storage(): string
    {
        return 'sqlite';
    }
}
