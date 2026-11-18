<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\QueryBuilder;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;

/** A subquery on one SQL driver runs in the database: no list of values is bound. */
final class SubselectSqliteTest extends TestCase
{
    public function test_a_subquery_larger_than_the_binding_limit_runs_in_the_database(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE `installs` (`uuid` TEXT PRIMARY KEY, `kind` TEXT)');
        $pdo->exec('CREATE TABLE `visits` (`uuid` TEXT PRIMARY KEY, `install` TEXT)');
        $pdo->beginTransaction();
        $install = $pdo->prepare('INSERT INTO `installs` VALUES (?, ?)');
        for ($i = 0; $i < 40000; $i++) {
            $install->execute(["i{$i}", 'web']);
        }
        $pdo->exec("INSERT INTO `visits` VALUES ('v1', 'i39999'), ('v2', 'none')");
        $pdo->commit();
        $driver = new SqliteDriver($pdo);

        $installs = new TypeDefinition('installs', 'installs', 'default', ['uuid' => new TypeFieldDefinition('uuid'), 'kind' => new TypeFieldDefinition('kind')]);
        $visits = new TypeDefinition('visits', 'visits', 'default', ['uuid' => new TypeFieldDefinition('uuid'), 'install' => new TypeFieldDefinition('install')]);

        $in = QueryBuilder::forType($driver, $visits)->whereIn('install', QueryBuilder::forType($driver, $installs)->where('kind', 'web'), 'uuid');
        $this->assertSame(['v1'], $in->pluck('uuid'));
        $out = QueryBuilder::forType($driver, $visits)->whereNotIn('install', QueryBuilder::forType($driver, $installs)->where('kind', 'web'), 'uuid');
        $this->assertSame(['v2'], $out->pluck('uuid'));
    }
}
