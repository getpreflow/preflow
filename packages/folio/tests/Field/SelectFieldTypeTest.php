<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Field;

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
        $dir = sys_get_temp_dir() . '/folio_select_' . bin2hex(random_bytes(4));
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
}
