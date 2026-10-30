<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests\Machine;

use PHPUnit\Framework\TestCase;
use Preflow\Ai\Settings\AiSettingsStore;
use Preflow\I18n\Machine\Glossary;
use Preflow\I18n\Machine\GlossaryEntry;
use Preflow\I18n\Machine\TranslationSettings;

/** Config supplies the defaults per level; a stored edit replaces only its own level. */
final class TranslationSettingsTest extends TestCase
{
    private function store(array $data = []): AiSettingsStore
    {
        return new class ($data) implements AiSettingsStore {
            public function __construct(public array $data) {}

            public function get(string $section): array
            {
                return $this->data[$section] ?? [];
            }

            public function put(string $section, array $data): void
            {
                $this->data[$section] = $data;
            }
        };
    }

    private function config(): array
    {
        return [
            'tuning' => ['A'],
            'translation' => [
                'languages' => ['de' => ['B'], 'fr' => ['F']],
                'types' => ['game' => ['C']],
                'glossary' => [['term' => 'Meeple', 'translations' => ['de' => null]]],
            ],
        ];
    }

    public function test_levels_merge_in_order(): void
    {
        $settings = new TranslationSettings($this->config(), $this->store());
        $this->assertSame(['A', 'B', 'C'], $settings->tuning('de', 'game')->instructions);
        $this->assertSame(['A', 'B'], $settings->tuning('de')->instructions);
    }

    public function test_other_language_not_included(): void
    {
        $settings = new TranslationSettings($this->config(), $this->store());
        $this->assertSame(['A', 'F', 'C'], $settings->tuning('fr', 'game')->instructions);
        $this->assertSame(['A'], $settings->tuning('it')->instructions);
    }

    public function test_stored_level_replaces_config_level_only(): void
    {
        $settings = new TranslationSettings($this->config(), $this->store(['tuning' => ['lang:de' => ['X', 'Y']]]));
        $this->assertSame(['A', 'X', 'Y', 'C'], $settings->tuning('de', 'game')->instructions);
        $this->assertSame(['A', 'F', 'C'], $settings->tuning('fr', 'game')->instructions);
    }

    public function test_unit_tuning_field_then_kind_from_model_json_and_stored_override(): void
    {
        $fieldAi = ['tuning' => 'D', 'kinds' => ['quiz-option' => 'E']];
        $settings = new TranslationSettings([], $this->store());
        $this->assertSame("D\nE", $settings->unitTuning('game', 'flow', 'quiz-option', $fieldAi));
        $this->assertSame('D', $settings->unitTuning('game', 'flow', null, $fieldAi));
        $this->assertSame('D', $settings->unitTuning('game', 'flow', 'other', $fieldAi));
        $this->assertNull($settings->unitTuning('game', 'flow', 'quiz-option'));

        $settings->saveLevel('game.flow', ['X']);
        $this->assertSame("X\nE", $settings->unitTuning('game', 'flow', 'quiz-option', $fieldAi));

        $listed = ['tuning' => ['D1', 'D2'], 'kinds' => ['k' => ['E1', 'E2']]];
        $this->assertSame("D1\nD2\nE1\nE2", (new TranslationSettings([], $this->store()))->unitTuning('t', 'f', 'k', $listed));
    }

    public function test_stored_kind_level_replaces_kind_default_only(): void
    {
        $fieldAi = ['tuning' => 'D', 'kinds' => ['k' => 'E']];
        $settings = new TranslationSettings([], $this->store(['tuning' => ['game.flow:k' => ['Z']]]));
        $this->assertSame("D\nZ", $settings->unitTuning('game', 'flow', 'k', $fieldAi));
    }

    public function test_glossary_stored_wins_over_config(): void
    {
        $store = $this->store();
        $settings = new TranslationSettings($this->config(), $store);
        $this->assertSame('Meeple', $settings->glossary()->toArray()[0]['term']);

        $settings->saveGlossary(new Glossary([new GlossaryEntry('Dice', ['de' => 'Würfel'])]));
        $rows = $settings->glossary()->toArray();
        $this->assertCount(1, $rows);
        $this->assertSame('Dice', $rows[0]['term']);
    }

    public function test_save_level_empty_list_removes_stored_override(): void
    {
        $store = $this->store();
        $settings = new TranslationSettings($this->config(), $store);
        $settings->saveLevel('project', ['Z']);
        $this->assertSame(['Z', 'B'], $settings->tuning('de')->instructions);

        $settings->saveLevel('project', []);
        $this->assertSame(['A', 'B'], $settings->tuning('de')->instructions);
        $this->assertArrayNotHasKey('project', $store->get('tuning'));
    }

    public function test_levels_view_merges_config_and_stored(): void
    {
        $settings = new TranslationSettings($this->config(), $this->store(['tuning' => ['game.flow' => ['X'], 'game' => ['G']]]));
        $this->assertSame([
            'project' => ['A'],
            'lang:de' => ['B'],
            'lang:fr' => ['F'],
            'game' => ['G'],
            'game.flow' => ['X'],
        ], $settings->levels());
    }
}
