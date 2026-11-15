<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Text;

use Preflow\Data\Driver\MysqlDialect;

/**
 * Asks a MySQL/MariaDB server how its LIKE compares single characters: the weights of a
 * collation (WEIGHT_STRING) and LIKE answers for pairs, through the SQL MysqlDialect::likeSql()
 * writes (with a collation or the LOWER() fallback). bin/text-fold-table derives TextMatch's
 * fold table from it; TextMatchServerProbeTest checks the table against it.
 */
final class CollationProbe
{
    private const int BATCH = 2000;

    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * Every assigned code point but controls, surrogates and private use (ICU's character
     * database), up to $max.
     *
     * @return list<int>
     */
    public static function codePoints(int $max = 0x10FFFF): array
    {
        $skip = [\IntlChar::CHAR_CATEGORY_CONTROL_CHAR, \IntlChar::CHAR_CATEGORY_SURROGATE, \IntlChar::CHAR_CATEGORY_PRIVATE_USE_CHAR, \IntlChar::CHAR_CATEGORY_UNASSIGNED];
        $points = [];
        for ($cp = 0; $cp <= $max; $cp++) {
            if (!in_array(\IntlChar::charType($cp), $skip, true)) {
                $points[] = $cp;
            }
        }

        return $points;
    }

    /**
     * The character a candidate fold suggests: case folding (simple) after the compatibility
     * mapping (NFKC) where that gives one character, repeated until it stays. Only a suggestion:
     * the server decides which of these pairs LIKE takes as equal.
     */
    public static function compatibilityFold(string $char): string
    {
        for ($i = 0; $i < 3; $i++) {
            $compatible = \Normalizer::normalize($char, \Normalizer::FORM_KC);
            $next = mb_convert_case(is_string($compatible) && mb_strlen($compatible, 'UTF-8') === 1 ? $compatible : $char, MB_CASE_FOLD_SIMPLE, 'UTF-8');
            if ($next === $char) {
                break;
            }
            $char = $next;
        }

        return $char;
    }

    /**
     * The pairs worth asking about: each character with its fold (TextMatch's table), with its
     * relatives (relatives()), and with the first character of equal weights
     * under $collation (null: none). Each pair once.
     *
     * @param list<int> $points
     * @param array<string, string> $table
     * @return list<array{string, string}>
     */
    public function candidatePairs(array $points, array $table, ?string $collation): array
    {
        $pairs = [];
        $add = static function (string $a, string $b) use (&$pairs): void {
            if ($a !== $b) {
                $pairs[min($a, $b) . "\0" . max($a, $b)] = [$a, $b];
            }
        };
        $chars = [];
        foreach ($points as $cp) {
            $chars[mb_chr($cp, 'UTF-8')] = true;
        }
        foreach ($table as $char => $fold) {
            $add((string) $char, $fold);
        }
        foreach (array_keys($chars) as $char) {
            foreach (self::relatives((string) $char) as $relative) {
                if (isset($chars[$relative])) {
                    $add((string) $char, $relative);
                }
            }
        }
        if ($collation !== null) {
            $first = [];
            foreach ($this->weights($points, $collation) as $cp => $weight) {
                $char = mb_chr($cp, 'UTF-8');
                isset($first[$weight]) ? $add($first[$weight], $char) : $first[$weight] = $char;
            }
        }

        return array_values($pairs);
    }

    /**
     * Where the server and TextMatch disagree on "a LIKE b and b LIKE a" (each taken literally).
     *
     * @param list<array{string, string}> $pairs
     * @return list<array{string, string, bool}> a, b, the server's answer (TextMatch's is the opposite)
     */
    public function disagreements(array $pairs, MysqlDialect $dialect): array
    {
        $both = [];
        foreach ($pairs as [$a, $b]) {
            $both[] = [$a, $b];
            $both[] = [$b, $a];
        }
        $answers = $this->likes($both, $dialect);
        $literal = static fn (string $s): string => strtr($s, ['!' => '!!', '%' => '!%', '_' => '!_']);
        $found = [];
        foreach ($pairs as $i => [$a, $b]) {
            $server = $answers[2 * $i] && $answers[2 * $i + 1];
            $php = \Preflow\Data\Text\TextMatch::like($a, $literal($b), '!') && \Preflow\Data\Text\TextMatch::like($b, $literal($a), '!');
            if ($server !== $php) {
                $found[] = [$a, $b, $server];
            }
        }

        return $found;
    }

    /** "U+0130 İ ~ U+0069 i: server yes" */
    public static function describe(array $disagreement): string
    {
        [$a, $b, $server] = $disagreement;
        $show = static fn (string $c): string => sprintf('U+%04X %s', mb_ord($c, 'UTF-8'), \IntlChar::isprint(mb_ord($c, 'UTF-8')) ? $c : '');

        return $show($a) . ' ~ ' . $show($b) . ': server ' . ($server ? 'yes' : 'no') . ', TextMatch ' . ($server ? 'no' : 'yes');
    }

    /**
     * The characters a character may be taken as: its compatibility fold, its simple lower and
     * upper case (İ and i, which case folding leaves apart).
     *
     * @return list<string>
     */
    public static function relatives(string $char): array
    {
        $cp = mb_ord($char, 'UTF-8');

        return array_values(array_unique([
            self::compatibilityFold($char),
            mb_chr((int) \IntlChar::tolower($cp), 'UTF-8'),
            mb_chr((int) \IntlChar::toupper($cp), 'UTF-8'),
        ]));
    }

    public function version(): string
    {
        return (string) $this->pdo->query('SELECT VERSION()')->fetchColumn();
    }

    /**
     * Each code point's weights under the collation (hex; '' for a character it ignores).
     *
     * @param list<int> $points
     * @return array<int, string>
     */
    public function weights(array $points, string $collation): array
    {
        $this->pdo->exec('DROP TEMPORARY TABLE IF EXISTS preflow_probe_chars');
        $this->pdo->exec('CREATE TEMPORARY TABLE preflow_probe_chars (cp INT PRIMARY KEY, c VARCHAR(4) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL)');
        foreach (array_chunk($points, self::BATCH) as $chunk) {
            $values = [];
            foreach ($chunk as $cp) {
                array_push($values, $cp, mb_chr($cp, 'UTF-8'));
            }
            $this->pdo->prepare('INSERT INTO preflow_probe_chars VALUES ' . implode(',', array_fill(0, count($chunk), '(?, ?)')))->execute($values);
        }
        $weights = [];
        foreach ($this->pdo->query("SELECT cp, HEX(WEIGHT_STRING(c COLLATE {$collation})) FROM preflow_probe_chars")->fetchAll(\PDO::FETCH_NUM) as [$cp, $weight]) {
            $weights[(int) $cp] = (string) $weight;
        }
        $this->pdo->exec('DROP TEMPORARY TABLE preflow_probe_chars');

        return $weights;
    }

    /**
     * The server's answer to "value LIKE literal" for each pair (the literal's %, _ and ! escaped
     * with !), compared as $dialect writes it.
     *
     * @param list<array{string, string}> $pairs value, literal
     * @return list<bool>
     */
    public function likes(array $pairs, MysqlDialect $dialect): array
    {
        $this->pdo->exec('DROP TEMPORARY TABLE IF EXISTS preflow_probe_pairs');
        $this->pdo->exec('CREATE TEMPORARY TABLE preflow_probe_pairs (id INT PRIMARY KEY, v VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, p VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL)');
        foreach (array_chunk($pairs, self::BATCH, true) as $chunk) {
            $values = [];
            foreach ($chunk as $id => [$value, $literal]) {
                array_push($values, $id, $value, strtr($literal, ['!' => '!!', '%' => '!%', '_' => '!_']));
            }
            $this->pdo->prepare('INSERT INTO preflow_probe_pairs VALUES ' . implode(',', array_fill(0, count($chunk), '(?, ?, ?)')))->execute($values);
        }
        $like = str_replace('?', 'p', $dialect->likeSql('v', '', false, '!'));
        $answers = array_fill(0, count($pairs), false);
        foreach ($this->pdo->query("SELECT id, {$like} FROM preflow_probe_pairs")->fetchAll(\PDO::FETCH_NUM) as [$id, $match]) {
            $answers[(int) $id] = (int) $match === 1;
        }
        $this->pdo->exec('DROP TEMPORARY TABLE preflow_probe_pairs');

        return $answers;
    }
}
