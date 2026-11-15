<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Text;

require_once __DIR__ . '/CollationProbe.php';

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Preflow\Data\Driver\MysqlDialect;
use Preflow\Testing\MysqlTestDatabase;

/**
 * TextMatch's fold table against the server it was derived from (`composer test:mysql`): for
 * every assigned code point (but controls, surrogates and private use) paired with its fold, its
 * compatibility fold, its lower and upper case, and the characters of equal weights, the server's
 * LIKE (both ways, in the collation MysqlDriver picks) and TextMatch agree.
 */
#[Group('mysql')]
final class TextMatchServerProbeTest extends TestCase
{
    public function test_text_match_agrees_with_the_servers_collation_character_by_character(): void
    {
        $pdo = MysqlTestDatabase::pdo();
        $collation = MysqlDialect::serverMatchCollation($pdo);
        if ($collation === null) {
            $this->markTestSkipped('The server has no accent-sensitive, case-insensitive collation (see MysqlFallbackMatchingTest).');
        }
        $probe = new CollationProbe($pdo);
        $table = require __DIR__ . '/../../src/Text/fold-table.php';

        $pairs = $probe->candidatePairs(CollationProbe::codePoints(), $table, $collation);
        $found = $probe->disagreements($pairs, new MysqlDialect($collation));

        $this->assertGreaterThan(5000, count($pairs));
        $this->assertSame([], array_map(CollationProbe::describe(...), array_slice($found, 0, 100)), sprintf(
            '%d of %d pairs differ on %s (%s); regenerate the table with bin/text-fold-table.',
            count($found),
            count($pairs),
            $probe->version(),
            $collation,
        ));
    }
}
