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
use Preflow\Ai\TokenUsage;
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

    public function test_non_list_answer_is_split_and_retried(): void
    {
        $fake = new FakeAiClient([FakeAiClient::json(['n' => 1, 'text' => 'Eins']), self::echo()]);

        $results = $this->translator($fake)->translateUnits([$this->unit('a', 'One'), $this->unit('b', 'Two')]);

        $this->assertSame(['a' => 'DE:One', 'b' => 'DE:Two'], self::texts($results));
        $this->assertSame([2, 1, 1], array_map(static fn ($r) => count(self::items($r)), $fake->requests()));
    }

    public function test_single_unit_with_invalid_answers_fails_with_reason(): void
    {
        $broken = new AiResponse('{"n": 1, "text": ', StopReason::Complete, new TokenUsage(), 'fake', 'fake-model');
        $fake = new FakeAiClient([$broken]);

        $result = $this->translator($fake)->translateUnits([$this->unit('a', 'One')])[0];

        $this->assertSame(UnitStatus::Failed, $result->status);
        $this->assertStringContainsString('not valid JSON', $result->error);
        $this->assertCount(1, $fake->requests());

        $notList = $this->translator(new FakeAiClient([FakeAiClient::json('Eins')]))->translateUnits([$this->unit('a', 'One')])[0];
        $this->assertSame('the answer is not a list', $notList->error);
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

    public function test_a_result_that_alters_a_protected_token_fails_that_unit(): void
    {
        $fake = new FakeAiClient([FakeAiClient::json([
            ['n' => 1, 'text' => 'Nimm einen {{glossary:figur|Meeple}}'],
            ['n' => 2, 'text' => 'Wirf die {{glossary:dice|Würfel}}'],
        ])]);
        $pattern = '/\{\{glossary:(\w+)(?:\|[^{}\r\n]*)?\}\}/';

        $results = $this->translator($fake, ['translation' => ['protected_tokens' => [$pattern]]])->translateUnits([
            $this->unit('a', 'Take a {{glossary:meeple}}'),
            $this->unit('b', 'Roll the {{glossary:dice|dice}}'),
        ]);

        $this->assertSame(UnitStatus::Failed, $results[0]->status);
        $this->assertSame('markup changed', $results[0]->error);
        $this->assertSame(UnitStatus::Translated, $results[1]->status, 'the shown text may be translated');
        $system = $fake->requests()[0]->tuning->system();
        $this->assertStringContainsString($pattern, $system, 'the model is told which tokens to keep');
    }

    public function test_an_invalid_protected_token_pattern_stops_before_any_request(): void
    {
        $fake = new FakeAiClient([self::echo()]);

        try {
            $this->translator($fake, ['translation' => ['protected_tokens' => ['/(unclosed/']]])->translateUnits([$this->unit('a', 'Hello')]);
            $this->fail('InvalidArgumentException expected');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('translation.protected_tokens[0]', $e->getMessage());
        }
        $this->assertSame([], $fake->requests(), 'nothing paid for');
    }

    public function test_without_protected_tokens_the_prompt_has_no_token_rule(): void
    {
        $fake = new FakeAiClient([self::echo()]);

        $this->translator($fake)->translateUnits([$this->unit('a', 'Hello')]);

        $this->assertStringNotContainsString('regular expression', $fake->requests()[0]->tuning->system());
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

    public function test_a_current_translation_without_previous_source_is_adapted_not_replaced(): void
    {
        $fake = new FakeAiClient([self::echo()]);
        $unit = new TranslationUnit(id: 'title', label: 'Title', source: 'Take two cards', to: 'de', from: 'en', current: 'Nimm zwei Karten');

        $this->translator($fake)->translateUnits([$unit]);

        $request = $fake->requests()[0];
        $this->assertSame('Nimm zwei Karten', self::items($request)[0]['current']);
        $this->assertArrayNotHasKey('previous', self::items($request)[0]);
        $this->assertStringContainsString('adapt "current" to the text, keep its wording where it still fits', $request->tuning->system());
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

    public function test_truncation_and_a_broken_answer_split(): void
    {
        $fake = new FakeAiClient([
            self::stopped(StopReason::Truncated),
            self::stopped(StopReason::Refused, 'cyber'),
            self::echo(),
        ]);

        $results = $this->translator($fake)->translateUnits([$this->unit('a', 'One'), $this->unit('b', 'Two')]);

        $this->assertSame(UnitStatus::Failed, $results[0]->status);
        $this->assertStringContainsString('refused', $results[0]->error);
        $this->assertSame('DE:Two', $results[1]->text);
        $this->assertSame([2, 1, 1], array_map(static fn ($r) => count(self::items($r)), $fake->requests()));
    }

    public function test_a_request_without_an_answer_fails_its_units_once_without_splitting(): void
    {
        $fake = new FakeAiClient([new AiRequestFailed('Claude returned HTTP 529: Overloaded'), self::echo()]);
        $units = [$this->unit('a', 'One'), $this->unit('b', 'Two'), $this->unit('c', 'Three', 'fr')];

        $results = $this->translator($fake)->translateUnits($units);

        $this->assertSame([UnitStatus::Failed, UnitStatus::Failed, UnitStatus::Translated], array_map(static fn ($r) => $r->status, $results));
        $this->assertSame('Claude returned HTTP 529: Overloaded', $results[0]->error);
        $this->assertSame('Claude returned HTTP 529: Overloaded', $results[1]->error);
        $this->assertSame([2, 1], array_map(static fn ($r) => count(self::items($r)), $fake->requests()), 'no halves for a provider error');

        $single = new FakeAiClient([new AiRequestFailed('rate limited')]);
        $result = $this->translator($single)->translateUnits([$this->unit('a', 'One')])[0];
        $this->assertSame(UnitStatus::Failed, $result->status);
        $this->assertStringContainsString('rate limited', $result->error);
        $this->assertCount(1, $single->requests());
    }

    public function test_two_requests_in_a_row_without_an_answer_mean_the_provider_is_not_reachable(): void
    {
        $fake = new FakeAiClient([new AiRequestFailed('timeout'), new AiRequestFailed('timeout'), self::echo()]);
        $units = [$this->unit('a', 'A', 'de'), $this->unit('b', 'B', 'fr'), $this->unit('c', 'C', 'it')];

        try {
            $this->translator($fake)->translateUnits($units);
            $this->fail('AiUnavailable expected');
        } catch (AiUnavailable $e) {
            $this->assertStringContainsString('not reachable', $e->getMessage());
            $this->assertStringContainsString('timeout', $e->getMessage());
        }
        $this->assertCount(2, $fake->requests(), 'the run stops instead of trying every batch');

        // An answer in between resets the count; so does a new call.
        $mixed = new FakeAiClient([new AiRequestFailed('timeout'), self::echo(), new AiRequestFailed('timeout'), self::echo()]);
        $results = $this->translator($mixed)->translateUnits([...$units, $this->unit('d', 'D', 'es')]);
        $this->assertSame([UnitStatus::Failed, UnitStatus::Translated, UnitStatus::Failed, UnitStatus::Translated], array_map(static fn ($r) => $r->status, $results));

        $again = new FakeAiClient([new AiRequestFailed('timeout'), self::echo()]);
        $translator = $this->translator($again);
        $translator->translateUnits([$this->unit('a', 'A')]);
        $this->assertSame(UnitStatus::Translated, $translator->translateUnits([$this->unit('a', 'A')])[0]->status);
    }

    public function test_requests_ask_for_low_reasoning(): void
    {
        $fake = new FakeAiClient([self::echo()]);

        $this->translator($fake)->translateUnits([$this->unit('a', 'A'), $this->unit('b', 'B', 'fr')]);

        $this->assertSame([true, true], array_map(static fn (AiRequest $r) => $r->lowReasoning, $fake->requests()));
    }

    public function test_an_unpriced_request_reports_an_unknown_cost(): void
    {
        $fake = new FakeAiClient([self::echo()]);
        $translator = new AiTranslator($this->ai($fake, ['models' => ['claude' => 'unpriced-model']]), $this->settings());
        $reported = [];
        $translator->reportCostTo(static function (?float $usd) use (&$reported): void {
            $reported[] = $usd;
        });

        $translator->translateUnits([$this->unit('a', 'A')]);

        $this->assertSame([null], $reported, 'null, never 0.0: the run shows the cost as unknown');
    }

    public function test_progress_is_reported_after_each_batch(): void
    {
        $fake = new FakeAiClient([self::echo()]);
        $translator = $this->translator($fake, batchChars: 12000);
        $reported = [];
        $translator->reportProgressTo(static function (int $done, int $total) use (&$reported): void {
            $reported[] = [$done, $total];
        });
        $units = [];
        for ($i = 0; $i < 300; $i++) {
            $units[] = $this->unit('u' . $i, str_pad((string) $i, 100, '.'));
        }

        $translator->translateUnits($units);

        $this->assertCount(count($fake->requests()), $reported, 'once per request');
        $done = array_column($reported, 0);
        $this->assertSame($done, array_values(array_unique($done)), 'growing');
        $this->assertSame([300, 300], end($reported));
        $this->assertSame([300], array_values(array_unique(array_column($reported, 1))));

        $reported = [];
        $translator->translateUnits([$this->unit('a', 'One'), $this->unit('b', 'Two')]);
        $this->assertSame([[2, 2]], $reported, 'each call counts its own units');

        $translator->reportProgressTo(null);
        $reported = [];
        $translator->translateUnits([$this->unit('a', 'One')]);
        $this->assertSame([], $reported, 'null stops it');
    }

    public function test_split_retries_count_as_they_finish(): void
    {
        $echo = self::echo();
        $fake = new FakeAiClient([static function (AiRequest $r) use ($echo): AiResponse {
            return in_array('unit 3', array_column(self::items($r), 'text'), true) ? self::stopped(StopReason::Blocked, 'SAFETY') : $echo($r);
        }]);
        $translator = $this->translator($fake);
        $reported = [];
        $translator->reportProgressTo(static function (int $done, int $total) use (&$reported): void {
            $reported[] = [$done, $total];
        });
        $units = [];
        for ($i = 1; $i <= 8; $i++) {
            $units[] = $this->unit('u' . $i, 'unit ' . $i);
        }

        $translator->translateUnits($units);

        // Finished: 1-2, 3 (failed), 4, 5-8; the failing requests in between count nothing.
        $this->assertSame([[2, 8], [3, 8], [4, 8], [8, 8]], $reported);
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

    public function test_a_full_batch_has_room_for_its_whole_answer(): void
    {
        // German of a 12,000-character batch with markup came back at 7,500 to 8,000 tokens: the old
        // floor of 8,000 cut answers off, and every cut answer was paid again in halves.
        $fake = new FakeAiClient([FakeAiClient::json([['n' => 1, 'text' => 'Eins'], ['n' => 2, 'text' => 'Zwei']])]);

        $this->translator($fake)->translateUnits([$this->unit('a', str_repeat('a', 6000)), $this->unit('b', str_repeat('b', 5900))]);

        $this->assertGreaterThanOrEqual(11900, $fake->requests()[0]->maxOutputTokens);
    }
}
