<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Preflow\Data\Driver\MysqlDriver;
use Preflow\Data\Query;
use Preflow\Data\Text\TextMatch;
use Preflow\Testing\MysqlTestDatabase;

/**
 * The LOWER() fallback a MySQL/MariaDB server without an accent-sensitive, case-insensitive
 * collation gets (`composer test:mysql`): it follows the rule but for the differences the data
 * README lists.
 */
#[Group('mysql')]
final class MysqlFallbackMatchingTest extends TestCase
{
    private \PDO $pdo;
    private MysqlDriver $driver;

    protected function setUp(): void
    {
        $this->pdo = MysqlTestDatabase::pdo();
        $this->pdo->exec('DROP TABLE IF EXISTS fallback_words');
        $this->pdo->exec('CREATE TABLE fallback_words (uuid VARCHAR(36) PRIMARY KEY, title VARCHAR(255))');
        $this->driver = new MysqlDriver($this->pdo, matchCollation: false);
        foreach (['u' => 'Über uns', 'e' => 'ÉTÉ', 's' => 'Straße', 't' => 'İstanbul', 'x' => "\u{212A}elvin", 'l' => 'Kelvin', 'f' => "\u{017F}uper"] as $id => $title) {
            $this->driver->save('fallback_words', $id, ['uuid' => $id, 'title' => $title]);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->pdo->exec('DROP TABLE IF EXISTS fallback_words');
        }
    }

    public function test_the_fallback_ignores_case_and_respects_accents(): void
    {
        $this->assertNull($this->driver->matchCollation());
        $this->assertSame(['u'], $this->ids('%über%'));
        $this->assertSame(['u'], $this->ids('%ÜBER%'));
        $this->assertSame([], $this->ids('%uber%'), 'accents count');
        $this->assertSame(['e'], $this->ids('été'));
        $this->assertSame([], $this->ids('ete'));
        $this->assertSame([], $this->ids('strasse'), 'ß is one letter');
        $this->assertSame(['s'], $this->ids('STRAẞE'));
        $this->assertSame(['l', 'x'], $this->ids('kelvin'), 'the Kelvin sign is a k');
        $this->assertSame(['l', 'x'], $this->ids("\u{212A}ELVIN"));
    }

    public function test_the_fallbacks_known_differences_are_documented(): void
    {
        $version = (string) $this->pdo->query('SELECT VERSION()')->fetchColumn();
        // Where the server's LOWER() could answer otherwise than TextMatch: marker => [stored, pattern].
        $probes = ['İstanbul' => ['t', 'istanbul'], 'ſ' => ['f', 'super']];
        $differences = [];
        foreach ($probes as $marker => [$id, $pattern]) {
            $stored = (string) $this->pdo->query('SELECT title FROM fallback_words WHERE uuid = ' . $this->pdo->quote($id))->fetchColumn();
            $server = in_array($id, $this->ids($pattern), true);
            if ($server !== TextMatch::like($stored, $pattern)) {
                $differences[] = $marker;
            }
        }

        $this->assertSame(['İstanbul', 'ſ'], $differences, "The LOWER() fallback on {$version} differs from the rule elsewhere than the data README says; update its list.");

        $readme = (string) file_get_contents(__DIR__ . '/../../README.md');
        $this->assertSame(1, preg_match('/^#### Without an accent-sensitive collation\n(.*?)(?=^#)/ms', $readme, $m), 'the README has the fallback section');
        $bullets = array_values(array_filter(explode("\n", $m[1]), static fn (string $line): bool => str_starts_with($line, '- ')));
        $this->assertCount(count($differences), $bullets, "The README lists exactly the fallback's differences found on {$version}.");
        foreach ($differences as $i => $marker) {
            $this->assertStringContainsString($marker, $bullets[$i], "The README lists the difference {$marker} (found on {$version}).");
        }
    }

    /** @return list<string> */
    private function ids(string $pattern): array
    {
        $ids = array_map('strval', array_column($this->driver->findMany('fallback_words', (new Query())->where('title', 'LIKE', $pattern))->items(), 'uuid'));
        sort($ids);

        return $ids;
    }
}
