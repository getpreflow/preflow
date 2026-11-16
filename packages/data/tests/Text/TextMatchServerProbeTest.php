<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Text;

require_once __DIR__ . '/CollationProbe.php';

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Preflow\Data\Driver\MysqlDialect;
use Preflow\Data\Migration\Grammar\MysqlGrammar;
use Preflow\Data\Text\TextMatch;
use Preflow\Testing\MysqlTestDatabase;

/**
 * TextMatch's tables against the server they were derived from (`composer test:mysql`): for
 * every assigned code point (but controls, surrogates and private use) paired with its fold, its
 * compatibility fold, its lower and upper case, and the characters of equal weights, the server's
 * LIKE (both ways) and TextMatch agree: in the search collation MysqlDriver picks for the
 * matching table (TextMatch::like()), in the text rule's collation for the fold table
 * (TextMatch::fold()); and its "=" and TextMatch::equals() agree.
 */
#[Group('mysql')]
final class TextMatchServerProbeTest extends TestCase
{
    /** @since 0.78.1 */
    public function test_matching_agrees_with_the_servers_search_collation_character_by_character(): void
    {
        $pdo = MysqlTestDatabase::pdo();
        $collation = (new MysqlGrammar())->on($pdo)->searchCollation();
        if ($collation === null) {
            $this->markTestSkipped('The server has no accent-insensitive, case-insensitive collation (see MysqlFallbackMatchingTest).');
        }
        $probe = new CollationProbe($pdo);
        $table = require __DIR__ . '/../../src/Text/search-fold-table.php';

        $pairs = $probe->candidatePairs(CollationProbe::codePoints(), $table, $collation);
        $found = $probe->disagreements($pairs, new MysqlDialect(null, $collation));

        $this->assertGreaterThan(10000, count($pairs));
        $this->assertSame([], array_map(CollationProbe::describe(...), array_slice($found, 0, 100)), sprintf(
            '%d of %d pairs differ on %s (%s); regenerate the table with bin/text-fold-table --search.',
            count($found),
            count($pairs),
            $probe->version(),
            $collation,
        ));
    }

    public function test_the_fold_table_agrees_with_the_servers_collation_character_by_character(): void
    {
        $pdo = MysqlTestDatabase::pdo();
        $collation = (new MysqlGrammar())->on($pdo)->textCollation();
        if ($collation === null) {
            $this->markTestSkipped('The server has no accent-sensitive, case-insensitive collation (see MysqlFallbackMatchingTest).');
        }
        $probe = new CollationProbe($pdo);
        $table = require __DIR__ . '/../../src/Text/fold-table.php';

        $pairs = $probe->candidatePairs(CollationProbe::codePoints(), $table, $collation);
        $found = $probe->disagreements($pairs, new MysqlDialect($collation), static fn (string $a, string $b): bool => TextMatch::fold($a) === TextMatch::fold($b));

        $this->assertGreaterThan(5000, count($pairs));
        $this->assertSame([], array_map(CollationProbe::describe(...), array_slice($found, 0, 100)), sprintf(
            '%d of %d pairs differ on %s (%s); regenerate the table with bin/text-fold-table.',
            count($found),
            count($pairs),
            $probe->version(),
            $collation,
        ));
    }

    /**
     * TextMatch::equals() against the server's "=" (the collation's NO PAD form): every code
     * point (controls too) with its fold, its canonical and compatibility decomposition, the
     * first character ICU's root order (secondary strength) takes as equal to it, and between
     * two letters (a character the collation ignores is left out by both).
     */
    public function test_equals_agrees_with_the_servers_equality(): void
    {
        $pdo = MysqlTestDatabase::pdo();
        $collation = (new MysqlGrammar())->on($pdo)->textCollation();
        if ($collation === null) {
            $this->markTestSkipped('The server has no accent-sensitive, case-insensitive collation (see MysqlFallbackMatchingTest).');
        }
        $collation = CollationProbe::noPad($collation);
        $probe = new CollationProbe($pdo);
        $icu = \Collator::create('root');
        $icu->setStrength(\Collator::SECONDARY);
        $icu->setAttribute(\Collator::NORMALIZATION_MODE, \Collator::ON);

        $pairs = [];
        $firstByKey = [];
        foreach (CollationProbe::codePoints(controls: true) as $cp) {
            $char = mb_chr($cp, 'UTF-8');
            $others = [TextMatch::fold($char), "a{$char}b" => 'ab'];
            foreach ([\Normalizer::FORM_D, \Normalizer::FORM_KD] as $form) {
                $others[] = (string) \Normalizer::normalize($char, $form);
            }
            $key = (string) $icu->getSortKey($char);
            $others[] = $firstByKey[$key] ??= $char;
            foreach ($others as $a => $b) {
                $a = is_string($a) ? $a : $char;
                if ($a !== $b && $b !== '') {
                    $pairs[$a . "\0" . $b] = [$a, $b];
                }
            }
        }
        $pairs = array_values($pairs);
        $found = [];
        foreach ($probe->equalities($pairs, $collation) as $i => $server) {
            [$a, $b] = $pairs[$i];
            if ($server !== TextMatch::equals($a, $b)) {
                $found[] = sprintf('%s = %s: server %s', json_encode($a), json_encode($b), $server ? 'yes' : 'no');
            }
        }

        $this->assertGreaterThan(100000, count($pairs));
        $this->assertSame([], array_slice($found, 0, 100), sprintf(
            '%d of %d pairs differ on %s (%s); regenerate the tables with bin/text-fold-table.',
            count($found),
            count($pairs),
            $probe->version(),
            $collation,
        ));
    }
}
