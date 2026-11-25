<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Field;

use Preflow\Folio\Tests\Support\TempRoots;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Content\FrontendConfigException;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\Types\SelectFieldType;

/**
 * The select field (board LibFields): up to 4 options for a single required choice (or one with a
 * default) as a segmented radiogroup; "display": "cards" with option descriptions as radio cards;
 * otherwise a select.
 */
final class SelectFieldTypeTest extends TestCase
{
    private const array UNITS = ['options' => ['m3' => 'm³', 't' => 't', 'm2' => 'm²']];

    public function test_up_to_four_options_of_a_required_choice_are_segmented(): void
    {
        $html = (new SelectFieldType())->renderEditor(new FieldContext(name: 'unit', label: 'Einheit', value: 't', config: self::UNITS, required: true));

        $this->assertStringContainsString('<fieldset class="form-group folio-choice-field" role="radiogroup"><legend>Einheit <span class="form-required">*</span></legend><div class="folio-segmented folio-segmented-choice">', $html);
        $this->assertStringContainsString('<label class="folio-segment"><input type="radio" name="unit" value="m3" required><span>m³</span></label>', $html);
        $this->assertStringContainsString('<label class="folio-segment"><input type="radio" name="unit" value="t" checked required><span>t</span></label>', $html);
        $this->assertStringNotContainsString('<select', $html);
    }

    public function test_a_default_makes_an_optional_choice_segmented_too(): void
    {
        // A new record gets the default as its value from the editor (AdminController), as before.
        $html = (new SelectFieldType())->renderEditor(new FieldContext(name: 'unit', value: 'm3', config: self::UNITS + ['default' => 'm3']));
        $this->assertStringContainsString('folio-segmented-choice', $html);
        $this->assertStringContainsString('<input type="radio" name="unit" value="m3" checked>', $html);
    }

    public function test_an_optional_choice_without_default_stays_a_select_with_none(): void
    {
        $html = (new SelectFieldType())->renderEditor(new FieldContext(name: 'unit', config: self::UNITS));
        $this->assertStringContainsString('<select', $html);
        $this->assertStringContainsString('<option value="" selected>—</option>', $html);
    }

    public function test_five_options_or_display_select_stay_a_select(): void
    {
        $five = ['options' => ['a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D', 'e' => 'E']];
        $this->assertStringContainsString('<select', (new SelectFieldType())->renderEditor(new FieldContext(name: 'x', config: $five, required: true)));
        $four = ['options' => ['a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D']];
        $this->assertStringContainsString('folio-segmented-choice', (new SelectFieldType())->renderEditor(new FieldContext(name: 'x', config: $four, required: true)));
        $this->assertStringContainsString('<select', (new SelectFieldType())->renderEditor(new FieldContext(name: 'x', config: $four + ['display' => 'select'], required: true)));
    }

    public function test_a_stale_value_counts_as_an_option(): void
    {
        $four = ['options' => ['a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D']];
        $html = (new SelectFieldType())->renderEditor(new FieldContext(name: 'x', value: 'gone', config: $four, required: true));
        $this->assertStringContainsString('<option value="gone" selected>gone (no longer an option)</option>', $html);
    }

    public function test_an_empty_placeholder_option_is_no_segment_and_does_not_count(): void
    {
        // A site's ad type: "– bitte wählen –" under "" and four real choices. The placeholder is the
        // radiogroup's own "nothing chosen", so four choices still segment, and none is checked.
        $config = ['options' => ['' => '– choose –', '1' => 'One', '2' => 'Two', '3' => 'Three', '4' => 'Four']];
        $html = (new SelectFieldType())->renderEditor(new FieldContext(name: 'type', config: $config, required: true));
        $this->assertStringContainsString('folio-segmented-choice', $html);
        $this->assertStringNotContainsString('– choose –', $html);
        $this->assertStringNotContainsString('value=""', $html);
        $this->assertStringNotContainsString('checked', $html);
        $this->assertSame(4, substr_count($html, 'class="folio-segment"'));
    }

    public function test_display_cards_renders_radio_cards_with_descriptions(): void
    {
        $config = ['display' => 'cards', 'options' => [
            '301' => ['label' => '301 · Moved for good', 'help' => 'Search engines move the ranking over.'],
            '302' => ['label' => ['en' => '302 · For now', 'de' => '302 · Vorübergehend'], 'help' => 'The old address comes back later.'],
        ]];
        $html = (new SelectFieldType())->renderEditor(new FieldContext(name: 'code', label: 'Answer', value: '301', config: $config));

        $this->assertStringContainsString('<fieldset class="form-group folio-choice-field folio-radio-cards-field" role="radiogroup"><legend>Answer</legend><div class="folio-radio-cards">', $html);
        $this->assertStringContainsString('<label class="folio-radio-card"><input type="radio" name="code" value="301" checked aria-describedby="code-301-help"><span class="folio-radio-card-text"><span class="folio-radio-card-label">301 · Moved for good</span><span class="folio-radio-card-help" id="code-301-help">Search engines move the ranking over.</span></span></label>', $html);
        $this->assertStringContainsString('<span class="folio-radio-card-label">302 · For now</span>', $html);
        $this->assertSame(['in:301,302'], (new SelectFieldType())->rules($config));
        $this->assertSame('302 · For now', (new SelectFieldType())->renderFrontend('302', $config));
    }

    public function test_cards_need_label_and_help_options(): void
    {
        foreach ([
            ['display' => 'cards', 'options' => ['a' => 'A']],
            ['display' => 'cards', 'options' => ['A', 'B']],
            ['display' => 'cards', 'options' => ['a' => ['help' => 'x']]],
            ['display' => 'cards', 'options' => ['a' => ['label' => 'A', 'help' => 3]]],
            ['display' => 'cards'],
        ] as $config) {
            try {
                SelectFieldType::display($config);
                $this->fail('accepted ' . json_encode($config));
            } catch (\LogicException $e) {
                $this->assertSame('Select field option "display": "cards" needs "options" as {value: {"label": …, "help": …}}.', $e->getMessage());
            }
        }
    }

    public function test_an_unknown_display_is_a_boot_error(): void
    {
        $dir = TempRoots::path('folio_select_');
        mkdir($dir);
        file_put_contents($dir . '/thing.json', (string) json_encode(['key' => 'thing', 'fields' => ['size' => ['type' => 'select', 'options' => ['a' => 'A'], 'display' => 'tiles']]]));
        try {
            (new TypeCatalog($dir))->all();
            $this->fail('accepted');
        } catch (FrontendConfigException $e) {
            $this->assertSame('Type "thing": field "size": Select field option "display" must be "select", "radio" or "cards".', $e->getMessage());
        } finally {
            unlink($dir . '/thing.json');
            rmdir($dir);
        }
    }

    // "options_from" (0.89): the options are the values of another field of the record being edited.

    private const array FROM = ['options_from' => 'answers'];

    public function test_options_from_shows_the_source_fields_values_as_a_select(): void
    {
        $html = (new SelectFieldType())->renderEditor(new FieldContext(name: 'answer', value: 'Yes', config: self::FROM, values: ['answers' => ['Yes', 'No', 'Later']]));

        $this->assertStringContainsString('<select', $html);
        $this->assertStringContainsString('<option value="Yes" selected>Yes</option>', $html);
        $this->assertStringContainsString('<option value="Later">Later</option>', $html);
        $this->assertStringNotContainsString('no longer an option', $html);
    }

    public function test_options_from_as_radio_chips(): void
    {
        $html = (new SelectFieldType())->renderEditor(new FieldContext(name: 'answer', config: self::FROM + ['display' => 'radio'], values: ['answers' => ['Yes', 'No']]));

        $this->assertStringContainsString('<input type="radio" name="answer" value="Yes"><span class="folio-chip-label">Yes</span>', $html);
        $this->assertStringContainsString('<input type="radio" name="answer" value="No"><span class="folio-chip-label">No</span>', $html);
    }

    public function test_options_from_reads_a_json_text_list_and_a_plain_text(): void
    {
        $type = new SelectFieldType();
        $json = $type->renderEditor(new FieldContext(name: 'answer', config: self::FROM, values: ['answers' => '["Yes"," No ",""]']));
        $this->assertStringContainsString('<option value="Yes">Yes</option>', $json);
        $this->assertStringContainsString('<option value="No">No</option>', $json, 'trimmed; empty entries dropped');
        $this->assertSame(['Yes', 'No'], SelectFieldType::sourceValues('["Yes"," No ",""]'));
        $this->assertSame(['Only one'], SelectFieldType::sourceValues(' Only one '));
        $this->assertSame([], SelectFieldType::sourceValues(null));
        $this->assertSame(['A'], SelectFieldType::sourceValues(['A', 'A', '']), 'each value once');
    }

    public function test_options_from_without_source_values_shows_a_note_naming_the_source(): void
    {
        $html = (new SelectFieldType())->renderEditor(new FieldContext(name: 'answer', label: 'Answer', config: self::FROM + ['options_from_label' => 'Possible answers'], required: true, errors: ['This field is required.']));

        $this->assertStringContainsString('<fieldset class="form-group folio-choice-field folio-options-from-empty has-error"><legend>Answer <span class="form-required">*</span></legend>', $html);
        $this->assertStringContainsString('<p class="form-help">Add values to Possible answers and save; they show up here as choices.</p>', $html);
        $this->assertStringContainsString('<div class="form-error">This field is required.</div>', $html);
        $this->assertStringNotContainsString('<select', $html);
        $this->assertStringNotContainsString('type="radio"', $html);

        $plain = (new SelectFieldType())->renderEditor(new FieldContext(name: 'answer', config: self::FROM, values: ['answers' => []]));
        $this->assertStringContainsString('Add values to Answers and save', $plain, 'without the label: the source name, humanized');
    }

    public function test_options_from_keeps_a_value_no_longer_among_the_sources_values(): void
    {
        $html = (new SelectFieldType())->renderEditor(new FieldContext(name: 'answer', value: 'Maybe', config: self::FROM, values: ['answers' => ['Yes', 'No']]));

        $this->assertStringContainsString('<option value="Maybe" selected>Maybe (no longer an option)</option>', $html);
    }

    public function test_options_from_validates_against_the_sources_values_in_the_same_data(): void
    {
        $validators = new \Preflow\Validation\ValidatorFactory(new \Preflow\Validation\RuleFactory());
        $rules = (new SelectFieldType())->rules(self::FROM);
        $this->assertCount(1, $rules);
        $this->assertNotContains('in:', array_filter($rules, 'is_string'));
        $check = static fn (array $data, array $extra = []): array => $validators->make(['answer' => [...$extra, ...$rules]], $data)->validate()->errors();

        $this->assertSame([], $check(['answer' => 'No', 'answers' => ['Yes', 'No']]));
        $this->assertSame([], $check(['answer' => 'No', 'answers' => '["Yes","No"]']), 'a JSON-text list');
        $this->assertSame(['answer' => ['Must be one of: Yes, No.']], $check(['answer' => 'Maybe', 'answers' => ['Yes', 'No']]));
        $this->assertSame([], $check(['answer' => null, 'answers' => ['Yes']]), 'empty passes');
        $this->assertSame([], $check(['answer' => '', 'answers' => []]), 'empty passes');
        $this->assertNotSame([], $check(['answer' => null, 'answers' => ['Yes']], ['required']), 'unless required');
        $this->assertNotSame([], $check(['answer' => 'Yes']), 'no source values: nothing is an option');
    }

    public function test_record_rules_read_the_source_through_its_field_type_and_name_it(): void
    {
        $dir = TempRoots::path('folio_select_rr_');
        mkdir($dir);
        file_put_contents($dir . '/poll.json', (string) json_encode(['key' => 'poll', 'table' => 'poll', 'fields' => [
            'answers' => ['type' => 'tags', 'label' => 'Possible answers'],
            'answer' => ['type' => 'select', 'options_from' => 'answers'],
        ]]));
        try {
            $def = (new \Preflow\Data\TypeRegistry($dir))->get('poll');
        } finally {
            unlink($dir . '/poll.json');
            rmdir($dir);
        }
        $types = new \Preflow\Folio\Field\FieldTypeRegistry();
        $types->register(new \Preflow\Folio\Field\Types\TagsFieldType());
        $types->register(new SelectFieldType());
        $rules = (new SelectFieldType())->recordRules(self::FROM, $def, $types);
        $validators = new \Preflow\Validation\ValidatorFactory(new \Preflow\Validation\RuleFactory());
        $check = static fn (array $data): array => $validators->make(['answer' => $rules], $data)->validate()->errors();

        $this->assertSame([], $check(['answer' => 'No', 'answers' => 'Yes, No']), 'an old comma text of a tags field gives its tags');
        $this->assertSame(['answer' => ['Must be one of: Yes, No.']], $check(['answer' => 'Yes, No', 'answers' => 'Yes, No']));
        $this->assertSame(['answer' => ['No choices yet: add values to Possible answers first.']], $check(['answer' => 'Yes', 'answers' => null]));
    }

    public function test_options_from_shows_the_value_as_its_label(): void
    {
        $this->assertSame('Yes &amp; no', (new SelectFieldType())->renderFrontend('Yes & no', self::FROM));
    }

    /** @param array<string, mixed> $fields */
    private static function bootError(array $fields, array $list = []): string
    {
        $dir = TempRoots::path('folio_select_from_');
        mkdir($dir);
        file_put_contents($dir . '/thing.json', (string) json_encode(['key' => 'thing', 'fields' => $fields] + ($list !== [] ? ['list' => $list] : [])));
        try {
            (new TypeCatalog($dir))->all();

            return '';
        } catch (FrontendConfigException $e) {
            return $e->getMessage();
        } finally {
            unlink($dir . '/thing.json');
            rmdir($dir);
        }
    }

    public function test_options_from_naming_no_field_of_the_type_is_a_boot_error(): void
    {
        $this->assertSame(
            'Type "thing": field "answer": Select field option "options_from" names "answer_list", which is not a field of this type; name one of its fields (answers).',
            self::bootError(['answers' => ['type' => 'tags'], 'answer' => ['type' => 'select', 'options_from' => 'answer_list']]),
        );
        $this->assertSame(
            'Type "thing": field "answer": Select field option "options_from" must name another field of this type, not the select itself.',
            self::bootError(['answers' => ['type' => 'tags'], 'answer' => ['type' => 'select', 'options_from' => 'answer']]),
        );
        $this->assertSame('', self::bootError(['answers' => ['type' => 'tags'], 'answer' => ['type' => 'select', 'options_from' => 'answers']]));
    }

    public function test_options_from_naming_a_structured_field_or_with_no_other_field_is_a_boot_error(): void
    {
        $this->assertSame(
            'Type "thing": field "answer": Select field option "options_from" names "sections", a blocks field; name a field of this type that holds values (such as tags).',
            self::bootError(['sections' => ['type' => 'blocks'], 'answer' => ['type' => 'select', 'options_from' => 'sections']]),
        );
        $this->assertSame(
            'Type "thing": field "answer": Select field option "options_from" names "rows", a matrix field; name a field of this type that holds values (such as tags).',
            self::bootError(['rows' => ['type' => 'matrix'], 'answer' => ['type' => 'select', 'options_from' => 'rows']]),
        );
        $this->assertSame(
            'Type "thing": field "answer": Select field option "options_from" names "answers", which is not a field of this type; the type has no other field: add one that holds the values (such as tags) and name it.',
            self::bootError(['answer' => ['type' => 'select', 'options_from' => 'answers']]),
        );
    }

    public function test_options_and_options_from_together_are_a_boot_error(): void
    {
        $this->assertSame(
            'Type "thing": field "answer": Select field has both "options" and "options_from"; keep one of them.',
            self::bootError(['answers' => ['type' => 'tags'], 'answer' => ['type' => 'select', 'options' => ['a'], 'options_from' => 'answers']]),
        );
        $this->assertSame(
            'Type "thing": field "answer": Select field option "display": "cards" needs fixed "options" with descriptions; it cannot take "options_from". Use "select" or "radio".',
            self::bootError(['answers' => ['type' => 'tags'], 'answer' => ['type' => 'select', 'options_from' => 'answers', 'display' => 'cards']]),
        );
    }

    public function test_options_from_on_another_field_type_is_a_boot_error(): void
    {
        $this->assertSame(
            'Type "thing": field "answer": "options_from" is an option of a select field; make the field "type": "select" or remove it',
            self::bootError(['answers' => ['type' => 'tags'], 'answer' => ['type' => 'string', 'options_from' => 'answers']]),
        );
    }

    public function test_an_options_from_select_cannot_be_a_list_filter_or_bulk_field(): void
    {
        $fields = ['answers' => ['type' => 'tags'], 'answer' => ['type' => 'select', 'options_from' => 'answers']];
        $this->assertSame(
            'Type "thing": list filter "answer" is a select with "options_from", whose options differ per record; remove it from "list.filters"',
            self::bootError($fields, ['filters' => ['answer']]),
        );
        $this->assertSame(
            'Type "thing": "list.bulk" names "answer", a select with "options_from", whose options differ per record; remove it from "list.bulk"',
            self::bootError($fields, ['bulk' => ['answer']]),
        );
    }
}
