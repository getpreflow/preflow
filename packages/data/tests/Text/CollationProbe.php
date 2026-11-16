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
     * Every assigned code point but surrogates, private use and (unless $controls) controls (ICU's
     * character database), up to $max.
     *
     * @return list<int>
     */
    public static function codePoints(int $max = 0x10FFFF, bool $controls = false): array
    {
        $skip = [\IntlChar::CHAR_CATEGORY_SURROGATE, \IntlChar::CHAR_CATEGORY_PRIVATE_USE_CHAR, \IntlChar::CHAR_CATEGORY_UNASSIGNED];
        if (!$controls) {
            $skip[] = \IntlChar::CHAR_CATEGORY_CONTROL_CHAR;
        }
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

    /**
     * The NO PAD form of a collation (MariaDB's utf8mb4_uca1400_as_ci pads, its
     * utf8mb4_uca1400_nopad_as_ci does not; MySQL's 0900 collations never pad): what "=" compares
     * with, so a trailing space counts and a lone space is no ignorable character.
     */
    public static function noPad(string $collation): string
    {
        return str_contains($collation, 'uca1400_') && !str_contains($collation, 'nopad')
            ? str_replace('uca1400_', 'uca1400_nopad_', $collation)
            : $collation;
    }

    /**
     * What "=" needs beyond the fold table (TextMatch::equals()): the characters the collation
     * ignores entirely (no weights: the soft hyphen, most controls), and the characters "=" takes
     * as another text where LIKE does not (their canonical, else compatibility decomposition: "Ü"
     * as "U" and a combining diaeresis, "ǆ" as "dž", "ﬁ" as "fi", a Kangxi radical as its
     * ideograph; or a character ICU's root order takes as equal), where the server says so. Each
     * expansion is keyed by the character's fold and given in folded, expanded form.
     *
     * @param array<string, string> $folds TextMatch's fold table
     * @return array{ignore: list<string>, expand: array<string, string>}
     */
    public function equalityTable(array $folds, string $collation): array
    {
        $collation = self::noPad($collation);
        $points = self::codePoints(controls: true);
        $ignore = [];
        foreach ($this->weights($points, $collation) as $cp => $weight) {
            if ($weight === '') {
                $ignore[mb_chr($cp, 'UTF-8')] = true;
            }
        }

        $icu = \Collator::create('root');
        $icu->setStrength(\Collator::SECONDARY);
        $icu->setAttribute(\Collator::NORMALIZATION_MODE, \Collator::ON);
        $firstByKey = [];
        $candidates = [];
        foreach ($points as $cp) {
            $char = mb_chr($cp, 'UTF-8');
            if (isset($ignore[$char])) {
                continue;
            }
            foreach ([\Normalizer::FORM_D, \Normalizer::FORM_KD] as $form) {
                $decomposed = \Normalizer::normalize($char, $form);
                if (is_string($decomposed) && $decomposed !== $char && $decomposed !== '') {
                    $candidates[$char][] = $decomposed;
                }
            }
            // A character ICU takes as an earlier one ("=" may too where LIKE does not, as for
            // the Kangxi radicals and their ideographs).
            $first = $firstByKey[(string) $icu->getSortKey($char)] ??= $char;
            if ($first !== $char) {
                $candidates[$char][] = $first;
            }
        }
        $pairs = [];
        foreach ($candidates as $char => $forms) {
            foreach (array_unique($forms) as $form) {
                $pairs[] = [(string) $char, $form];
            }
        }
        $equal = [];
        foreach ($this->equalities($pairs, $collation) as $i => $same) {
            if ($same) {
                $equal[$pairs[$i][0]] ??= $pairs[$i][1]; // canonical first
            }
        }

        $expand = [];
        $canon = static function (string $text) use (&$expand, $ignore, $folds): string {
            $out = '';
            foreach (mb_str_split($text, 1, 'UTF-8') as $char) {
                if (isset($ignore[$char])) {
                    continue;
                }
                $fold = $folds[$char] ?? $char;
                $out .= $expand[$fold] ?? $fold;
            }

            return $out;
        };
        // Decompositions hold no character that decomposes further; two rounds settle a fold
        // that lands on an expanded character.
        for ($round = 0; $round < 2; $round++) {
            foreach ($equal as $char => $decomposed) {
                $fold = $folds[(string) $char] ?? (string) $char;
                $form = $canon($decomposed);
                if ($form !== $fold) { // a decomposition whose other characters the collation ignores
                    $expand[$fold] = $form;
                }
            }
        }
        uksort($expand, static fn (string $a, string $b): int => mb_ord($a, 'UTF-8') <=> mb_ord($b, 'UTF-8'));

        // Every character must equal its canonical form on the server.
        $check = [];
        foreach ($points as $cp) {
            $char = mb_chr($cp, 'UTF-8');
            $form = $canon($char);
            if ($form !== $char && $form !== '') {
                $check[] = [$char, $form];
            }
        }
        $refused = [];
        foreach ($this->equalities($check, $collation) as $i => $same) {
            if (!$same) {
                $refused[] = sprintf('U+%04X', mb_ord($check[$i][0], 'UTF-8'));
            }
        }
        if ($refused !== []) {
            throw new \RuntimeException('"=" does not take these characters as their canonical form: ' . implode(', ', array_slice($refused, 0, 50)));
        }

        return ['ignore' => array_map('strval', array_keys($ignore)), 'expand' => $expand];
    }

    /**
     * The server's answer to "a = b" for each pair under the collation (both sides taken as
     * written, no padding with a NO PAD collation).
     *
     * @param list<array{string, string}> $pairs
     * @return list<bool>
     */
    public function equalities(array $pairs, string $collation): array
    {
        $this->pdo->exec('DROP TEMPORARY TABLE IF EXISTS preflow_probe_equal');
        $this->pdo->exec('CREATE TEMPORARY TABLE preflow_probe_equal (id INT PRIMARY KEY, a VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, b VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL)');
        foreach (array_chunk($pairs, self::BATCH, true) as $chunk) {
            $values = [];
            foreach ($chunk as $id => [$a, $b]) {
                array_push($values, $id, $a, $b);
            }
            $this->pdo->prepare('INSERT INTO preflow_probe_equal VALUES ' . implode(',', array_fill(0, count($chunk), '(?, ?, ?)')))->execute($values);
        }
        $answers = array_fill(0, count($pairs), false);
        foreach ($this->pdo->query("SELECT id, (a COLLATE {$collation}) = (b COLLATE {$collation}) FROM preflow_probe_equal")->fetchAll(\PDO::FETCH_NUM) as [$id, $same]) {
            $answers[(int) $id] = (int) $same === 1;
        }
        $this->pdo->exec('DROP TEMPORARY TABLE preflow_probe_equal');

        return $answers;
    }
}
