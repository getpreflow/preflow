<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests\Machine;

use PHPUnit\Framework\TestCase;
use Preflow\Ai\AiRequest;
use Preflow\Ai\AiRequestFailed;
use Preflow\Ai\AiResponse;
use Preflow\Ai\AiUnavailable;
use Preflow\Ai\StopReason;
use Preflow\Ai\Testing\FakeAiClient;
use Preflow\Ai\Tuning;
use Preflow\I18n\Machine\AiTranslator;
use Preflow\I18n\Machine\TextFormat;
use Preflow\I18n\Machine\TranslationFailed;
use Preflow\I18n\Machine\TranslationResult;
use Preflow\I18n\Machine\TranslationUnit;
use Preflow\I18n\Machine\UnitStatus;

/** Batches go out as JSON items and come back mapped by n; failures stay with the unit they belong to. */
final class AiTranslatorTest extends TestCase
{
    use MachineTestKit;

    private function unit(string $id, string $source, string $to = 'de', ?string $type = null, TextFormat $format = TextFormat::Plain): TranslationUnit
    {
        return new TranslationUnit(id: $id, label: 'Label ' . $id, source: $source, to: $to, from: 'en', format: $format, type: $type);
    }

    /** @param array<string,mixed> $aiConfig */
    private function translator(FakeAiClient $fake, array $aiConfig = [], int $batchChars = 12000): AiTranslator
    {
        return new AiTranslator($this->ai($fake), $this->settings($aiConfig), $batchChars);
    }

    /** @param list<TranslationResult> $results @return array<string,?string> */
    private static function texts(array $results): array
    {
        $out = [];
        foreach ($results as $r) {
            $out[$r->id] = $r->text;
        }

        return $out;
    }

    public function test_maps_results_by_n_not_order(): void
    {
        $fake = new FakeAiClient([FakeAiClient::json([
            ['n' => 3, 'text' => 'Drei'],
            ['n' => 1, 'text' => 'Eins'],
            ['n' => 2, 'text' => 'Zwei'],
        ])]);

        $results = $this->translator($fake)->translateUnits([$this->unit('a', 'One'), $this->unit('b', 'Two'), $this->unit('c', 'Three')]);

        $this->assertSame(['a' => 'Eins', 'b' => 'Zwei', 'c' => 'Drei'], self::texts($results));
        $this->assertSame([UnitStatus::Translated, UnitStatus::Translated, UnitStatus::Translated], array_map(static fn ($r) => $r->status, $results));
        $this->assertCount(1, $fake->requests());
        $request = $fake->requests()[0];
        $this->assertSame([1, 2, 3], array_column(self::items($request), 'n'));
        $this->assertSame([
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => ['n' => ['type' => 'integer'], 'text' => ['type' => 'string']],
                'required' => ['n', 'text'],
                'additionalProperties' => false,
            ],
        ], $request->schema);
    }

    public function test_missing_item_fails_only_that_unit_extra_ignored(): void
    {
        $fake = new FakeAiClient([FakeAiClient::json([
            ['n' => 1, 'text' => 'Eins'],
            ['n' => 1, 'text' => 'Duplicate'],
            ['n' => 9, 'text' => 'Unknown'],
            ['n' => 3, 'text' => 'Drei'],
        ])]);

        $results = $this->translator($fake)->translateUnits([$this->unit('a', 'One'), $this->unit('b', 'Two'), $this->unit('c', 'Three')]);

        $this->assertSame(['a' => 'Eins', 'b' => null, 'c' => 'Drei'], self::texts($results));
        $this->assertSame(UnitStatus::Failed, $results[1]->status);
        $this->assertSame('no answer', $results[1]->error);
        $this->assertCount(1, $fake->requests(), 'a missing item is not retried');
    }

    public function test_non_list_answer_fails_the_whole_request(): void
    {
        $fake = new FakeAiClient([FakeAiClient::json(['n' => 1, 'text' => 'Eins'])]);

        $results = $this->translator($fake)->translateUnits([$this->unit('a', 'One'), $this->unit('b', 'Two')]);

        $this->assertSame([UnitStatus::Failed, UnitStatus::Failed], array_map(static fn ($r) => $r->status, $results));
        $this->assertNotNull($results[0]->error);
    }

    public function test_markup_violation_fails_unit(): void
    {
        $fake = new FakeAiClient([FakeAiClient::json([
            ['n' => 1, 'text' => 'Hallo'],
            ['n' => 2, 'text' => '<p>Gut <b>so</b></p>'],
        ])]);

        $results = $this->translator($fake)->translateUnits([
            $this->unit('a', 'Hello {name}'),
            $this->unit('b', '<p>Fine <b>so</b></p>', format: TextFormat::Html),
        ]);

        $this->assertSame(UnitStatus::Failed, $results[0]->status);
        $this->assertSame('markup changed', $results[0]->error);
        $this->assertNull($results[0]->text);
        $this->assertSame(UnitStatus::Translated, $results[1]->status);
        $this->assertSame('html', self::items($fake->requests()[0])[1]['format']);
        $this->assertArrayNotHasKey('format', self::items($fake->requests()[0])[0], 'plain is the default and not sent');
    }

    public function test_glossary_terms_in_system_and_mismatch_warning(): void
    {
        $fake = new FakeAiClient([FakeAiClient::json([['n' => 1, 'text' => 'Setze einen Meeple für Arbeiter']])]);
        $config = [
            'tuning' => ['Project rule.'],
            'translation' => [
                'languages' => ['de' => ['Use du.']],
                'glossary' => [
                    ['term' => 'Meeple', 'translations' => ['de' => null], 'note' => 'a game piece'],
                    ['term' => 'Worker Placement', 'translations' => ['de' => 'Arbeitereinsatz']],
                    ['term' => 'Dice', 'translations' => ['de' => 'Würfel']],
                ],
            ],
        ];

        $results = (new AiTranslator($this->ai($fake), $this->settings($config)))
            ->translateUnits([$this->unit('a', 'Place a meeple for worker placement')], 'Keep it short.');

        $system = $fake->requests()[0]->tuning->system();
        $this->assertStringContainsString('"Meeple" → keep as is (a game piece)', $system);
        $this->assertStringContainsString('"Worker Placement" → "Arbeitereinsatz"', $system);
        $this->assertStringNotContainsString('Dice', $system);
        $this->assertStringContainsString('Project rule.', $system);
        $this->assertStringContainsString('Use du.', $system);
        $this->assertStringContainsString('placeholders', $system);
        $this->assertStringContainsString('proper names, brand names or game names', $system);
        $this->assertStringContainsString('Answer only with the JSON', $system);
        $this->assertStringEndsWith('Keep it short.', $system, 'the one-off note comes last');
        $this->assertLessThan(strpos($system, 'Meeple'), strpos($system, 'Use du.'));

        $this->assertSame(UnitStatus::Translated, $results[0]->status);
        $this->assertSame(['glossary:Worker Placement'], $results[0]->warnings);
    }

    public function test_outdated_unit_sends_previous_and_current(): void
    {
        $fake = new FakeAiClient([self::echo()]);
        $unit = new TranslationUnit(
            id: 'step',
            label: 'Step 3 › Option B',
            source: 'Take two cards',
            to: 'de',
            from: 'en',
            kind: 'quiz-option',
            previousSource: 'Take one card',
            current: 'Nimm eine Karte',
            context: ['type' => 'Game', 'record' => 'Cascadia'],
            tuning: 'Imperative mood.',
        );

        $this->translator($fake)->translateUnits([$unit]);

        $request = $fake->requests()[0];
        $this->assertSame([[
            'n' => 1,
            'text' => 'Take two cards',
            'label' => 'Step 3 › Option B',
            'kind' => 'quiz-option',
            'previous' => 'Take one card',
            'current' => 'Nimm eine Karte',
            'hint' => 'Imperative mood.',
        ]], self::items($request));
        $this->assertStringContainsString('Cascadia', strtok($request->prompt, "\n"), 'the context is a header line');
        $this->assertStringContainsString('"previous"', $request->tuning->system());
        $this->assertStringContainsString('"current"', $request->tuning->system());
    }

    public function test_groups_by_language_pair_and_type_results_in_input_order(): void
    {
        $fake = new FakeAiClient([self::echo()]);

        $results = $this->translator($fake)->translateUnits([
            $this->unit('a', 'A', 'de', 'game'),
            $this->unit('b', 'B', 'fr', 'game'),
            $this->unit('c', 'C', 'de', 'article'),
            $this->unit('d', 'D', 'de', 'game'),
        ]);

        $this->assertSame(['a' => 'DE:A', 'b' => 'DE:B', 'c' => 'DE:C', 'd' => 'DE:D'], self::texts($results));
        $this->assertCount(3, $fake->requests());
        $this->assertSame(['A', 'D'], array_column(self::items($fake->requests()[0]), 'text'));
    }

    public function test_batches_split_by_char_budget(): void
    {
        $fake = new FakeAiClient([self::echo()]);
        $units = [];
        for ($i = 0; $i < 300; $i++) {
            $units[] = $this->unit('u' . $i, str_pad((string) $i, 100, '.'));
        }

        $results = $this->translator($fake, batchChars: 12000)->translateUnits($units);

        $this->assertGreaterThanOrEqual(3, count($fake->requests()));
        $sent = [];
        foreach ($fake->requests() as $request) {
            $chars = 0;
            foreach (self::items($request) as $item) {
                $chars += mb_strlen($item['text']) + mb_strlen($item['previous'] ?? '') + mb_strlen($item['current'] ?? '');
                $sent[] = $item['text'];
            }
            $this->assertLessThanOrEqual(12000, $chars);
        }
        $this->assertCount(300, $sent);
        $this->assertSame(array_map(static fn ($u) => $u->source, $units), $sent, 'every unit sent exactly once, in order');
        $this->assertCount(300, $results);
        foreach ($results as $i => $r) {
            $this->assertSame('u' . $i, $r->id);
            $this->assertSame('DE:' . $units[$i]->source, $r->text);
        }
    }

    public function test_unit_over_the_budget_goes_alone(): void
    {
        $fake = new FakeAiClient([self::echo()]);

        $this->translator($fake, batchChars: 100)->translateUnits([
            $this->unit('a', 'short'),
            $this->unit('b', str_repeat('long ', 50)),
            $this->unit('c', 'short too'),
        ]);

        $this->assertSame([1, 1, 1], array_map(static fn ($r) => count(self::items($r)), $fake->requests()));
    }

    public function test_blocked_request_halves_down_to_single_unit(): void
    {
        $answered = [];
        $echo = self::echo();
        $fake = new FakeAiClient([static function (AiRequest $r) use (&$answered, $echo): AiResponse {
            $texts = array_column(self::items($r), 'text');
            if (in_array('unit 3', $texts, true)) {
                return self::stopped(StopReason::Blocked, 'SAFETY');
            }
            array_push($answered, ...$texts);

            return $echo($r);
        }]);
        $units = [];
        for ($i = 1; $i <= 8; $i++) {
            $units[] = $this->unit('u' . $i, 'unit ' . $i);
        }

        $results = $this->translator($fake)->translateUnits($units);

        foreach ($results as $i => $r) {
            if ($r->id === 'u3') {
                $this->assertSame(UnitStatus::Failed, $r->status);
                $this->assertStringContainsString('blocked', $r->error);
                continue;
            }
            $this->assertSame(UnitStatus::Translated, $r->status, $r->id);
            $this->assertSame('DE:unit ' . ($i + 1), $r->text);
        }
        sort($answered);
        $this->assertSame(['unit 1', 'unit 2', 'unit 4', 'unit 5', 'unit 6', 'unit 7', 'unit 8'], $answered, 'answered units are never sent again');
        // Failing: 1-8, 1-4, 3-4, 3. Answered: 1-2, 4, 5-8.
        $this->assertSame([8, 4, 2, 2, 1, 1, 4], array_map(static fn ($r) => count(self::items($r)), $fake->requests()));
    }

    public function test_failed_request_and_truncation_split_too(): void
    {
        $fake = new FakeAiClient([
            new AiRequestFailed('timeout'),
            self::stopped(StopReason::Truncated),
            self::echo(),
        ]);

        $results = $this->translator($fake)->translateUnits([$this->unit('a', 'One'), $this->unit('b', 'Two')]);

        $this->assertSame(UnitStatus::Failed, $results[0]->status);
        $this->assertStringContainsString('truncated', $results[0]->error);
        $this->assertSame('DE:Two', $results[1]->text);

        $single = new FakeAiClient([new AiRequestFailed('rate limited')]);
        $result = $this->translator($single)->translateUnits([$this->unit('a', 'One')])[0];
        $this->assertSame(UnitStatus::Failed, $result->status);
        $this->assertStringContainsString('rate limited', $result->error);
        $this->assertCount(1, $single->requests());
    }

    public function test_recitation_single_unit_retried_at_temperature_one(): void
    {
        $fake = new FakeAiClient([self::stopped(StopReason::Blocked, 'RECITATION'), self::echo()]);

        $result = $this->translator($fake)->translateUnits([$this->unit('a', 'Famous rules text')])[0];

        $this->assertSame('DE:Famous rules text', $result->text);
        [$first, $retry] = $fake->requests();
        $this->assertNull($first->temperature);
        $this->assertSame(1.0, $retry->temperature);
        $this->assertStringContainsString('Word it freely in your own words.', $retry->tuning->system());
        $this->assertStringNotContainsString('Word it freely', $first->tuning->system());

        $again = new FakeAiClient([self::stopped(StopReason::Blocked, 'RECITATION')]);
        $result = $this->translator($again)->translateUnits([$this->unit('a', 'Famous rules text')])[0];
        $this->assertSame(UnitStatus::Failed, $result->status);
        $this->assertCount(2, $again->requests(), 'retried once only');
    }

    public function test_unavailable_propagates(): void
    {
        $fake = new FakeAiClient([self::echo(), new AiUnavailable('quota')]);

        try {
            $this->translator($fake)->translateUnits([$this->unit('a', 'A', 'de'), $this->unit('b', 'B', 'fr'), $this->unit('c', 'C', 'it')]);
            $this->fail('AiUnavailable expected');
        } catch (AiUnavailable $e) {
            $this->assertSame('quota', $e->getMessage());
        }
        $this->assertCount(2, $fake->requests(), 'the run stops at once');

        $off = new AiTranslator($this->ai(new FakeAiClient([self::echo()]), enabled: false), $this->settings());
        $this->assertSame([], $off->translateUnits([]));
        $this->expectException(AiUnavailable::class);
        $off->translateUnits([$this->unit('a', 'A')]);
    }

    public function test_translate_single_text_and_failure_throws(): void
    {
        $fake = new FakeAiClient([self::echo()]);
        $translator = $this->translator($fake);

        $this->assertSame('DE:<b>Hi</b>', $translator->translate('<b>Hi</b>', 'de', new Tuning(['Be brief.']), 'en', TextFormat::Html));
        $request = $fake->requests()[0];
        $this->assertStringContainsString('Be brief.', $request->tuning->system());
        $this->assertSame('html', self::items($request)[0]['format']);

        $refused = $this->translator(new FakeAiClient([self::stopped(StopReason::Refused)]));
        $this->expectException(TranslationFailed::class);
        $this->expectExceptionMessageMatches('/refused/');
        $refused->translate('Hello', 'de');
    }
}
