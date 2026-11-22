<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Preflow\Data\Driver\MysqlDriver;
use Preflow\Data\Maintenance;
use Preflow\Data\MaintenanceReport;
use Preflow\Testing\MysqlTestDatabase;

/** db:check and db:vacuum on MySQL/MariaDB (`composer test:mysql`): CHECK TABLE, OPTIMIZE TABLE, a collation report. */
#[Group('mysql')]
final class MysqlMaintenanceTest extends TestCase
{
    private MysqlDriver $driver;

    protected function setUp(): void
    {
        $pdo = MysqlTestDatabase::pdo();
        MysqlTestDatabase::dropAllTables($pdo);
        $this->driver = new MysqlDriver($pdo);
        $this->driver->execute('CREATE TABLE `notes` (`id` BIGINT AUTO_INCREMENT PRIMARY KEY, `slug` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL, `body` LONGTEXT COLLATE utf8mb4_unicode_ci) ENGINE=InnoDB');
        $this->driver->execute('CREATE TABLE `tags` (`id` BIGINT AUTO_INCREMENT PRIMARY KEY, `name` VARCHAR(100) COLLATE utf8mb4_bin) ENGINE=InnoDB');
        foreach (['a', 'b', 'c'] as $slug) {
            $this->driver->execute('INSERT INTO `notes` (`slug`, `body`) VALUES (?, ?)', [$slug, str_repeat($slug, 1000)]);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->driver)) {
            MysqlTestDatabase::dropAllTables($this->driver->pdo());
        }
    }

    /** @since 0.84.0 */
    public function test_the_driver_is_checkable_and_reports(): void
    {
        $this->assertInstanceOf(Maintenance::class, $this->driver);
        $this->assertInstanceOf(MaintenanceReport::class, $this->driver);
    }

    public function test_check_finds_nothing_in_a_sound_database(): void
    {
        $this->assertSame([], $this->driver->check());
    }

    public function test_compact_optimizes_every_table_and_keeps_the_rows(): void
    {
        $this->driver->execute('DELETE FROM `notes` WHERE `slug` = ?', ['b']);

        $this->driver->compact();

        $this->assertSame(2, (int) $this->driver->pdo()->query('SELECT COUNT(*) FROM `notes`')->fetchColumn());
        $this->assertSame([], $this->driver->check());
    }

    public function test_compact_refuses_inside_a_transaction(): void
    {
        $this->driver->pdo()->beginTransaction();
        try {
            $this->expectException(\LogicException::class);
            $this->driver->compact();
        } finally {
            $this->driver->pdo()->rollBack();
        }
    }

    public function test_the_report_counts_text_columns_per_collation_and_says_what_is_not_applicable(): void
    {
        $report = implode("\n", $this->driver->report());

        $this->assertStringContainsString('utf8mb4_bin: 2 column(s)', $report);
        $this->assertStringContainsString('utf8mb4_unicode_ci: 1 column(s)', $report);
        $this->assertStringContainsString('not applicable: foreign key references', $report);
    }

    public function test_a_table_the_engine_cannot_check_is_reported_never_passed(): void
    {
        $this->driver->execute('CREATE TABLE `scratch` (`id` INT PRIMARY KEY) ENGINE=MEMORY');

        $this->assertSame([], $this->driver->check(), 'not a problem: nothing was found');
        $notApplicable = array_values(array_filter($this->driver->report(), static fn (string $line): bool => str_starts_with($line, 'not applicable: scratch: ')));
        $this->assertCount(1, $notApplicable, 'the engine\'s note, so the check is not read as passed');
    }
}
