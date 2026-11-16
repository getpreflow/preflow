<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

require_once __DIR__ . '/../Text/CollationProbe.php';

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Preflow\Data\Driver\MysqlDialect;
use Preflow\Data\Migration\Grammar\MysqlGrammar;
use Preflow\Data\Driver\MysqlDriver;
use Preflow\Data\Query;
use Preflow\Data\Tests\Text\CollationProbe;
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
        $this->assertSame([], $this->ids('super'), 'the long s is a letter of its own');
        $this->assertSame(['f'], $this->ids("\u{017F}UPER"));
    }

    /**
     * The same probe as TextMatchServerProbeTest, through the fallback's SQL: every disagreement
     * falls into one of the kinds the data README lists, and every listed kind occurs.
     */
    public function test_the_fallbacks_known_differences_are_documented(): void
    {
        $probe = new CollationProbe($this->pdo);
        $version = $probe->version();
        $table = require __DIR__ . '/../../src/Text/fold-table.php';
        $pairs = $probe->candidatePairs(CollationProbe::codePoints(), $table, (new MysqlGrammar())->on($this->pdo)->textCollation());

        $kinds = [];
        $unexplained = [];
        foreach ($probe->disagreements($pairs, new MysqlDialect(null)) as $disagreement) {
            [$a, $b, $server] = $disagreement;
            $kind = match (true) {
                !$server => 'ａ', // only case is lowered: width, compatibility and other equal forms stay apart
                $server && in_array('İ', [$a, $b], true) => 'İstanbul',
                $server && mb_ord($a, 'UTF-8') > 0xFFFF && mb_ord($b, 'UTF-8') > 0xFFFF => '𐐀',
                default => null,
            };
            if ($kind === null) {
                $unexplained[] = CollationProbe::describe($disagreement);
            } else {
                $kinds[$kind] = true;
            }
        }

        $this->assertSame([], array_slice($unexplained, 0, 50), "The LOWER() fallback on {$version} differs in a way the data README does not list; add it.");
        $readme = (string) file_get_contents(__DIR__ . '/../../README.md');
        $this->assertSame(1, preg_match('/^#### Without an accent-sensitive collation\n(.*?)(?=^#)/ms', $readme, $m), 'the README has the fallback section');
        $bullets = array_values(array_filter(explode("\n", $m[1]), static fn (string $line): bool => str_starts_with($line, '- ')));
        $this->assertCount(count($kinds), $bullets, "The README lists exactly the kinds of difference found on {$version}: " . implode(', ', array_keys($kinds)));
        foreach (array_keys($kinds) as $marker) {
            $this->assertCount(1, array_filter($bullets, static fn (string $b): bool => str_contains($b, (string) $marker)), "The README lists the difference {$marker} (found on {$version}).");
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
