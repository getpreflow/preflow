<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Field;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Content\FrontendConfigException;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\Types\NumberFieldType;

/** The number field (board LibFields): a stepper (− value unit +) with the "unit" option. */
final class NumberFieldTypeTest extends TestCase
{
    public function test_the_stepper_wraps_the_number_input(): void
    {
        $html = (new NumberFieldType())->renderEditor(new FieldContext(name: 'factor', label: 'Faktor', value: '1.256', config: ['step' => 0.001, 'unit' => 'm³'], validate: ['required', 'min:0', 'max:10']));

        $this->assertMatchesRegularExpression(
            '~<div class="folio-stepper" data-folio-stepper data-min="0" data-max="10">'
            . '<button type="button" class="folio-stepper-button" data-folio-step="-1" aria-label="Decrease Faktor" title="Decrease Faktor">−</button>'
            . '<input type="number" name="factor" value="1.256" id="factor" step="0.001" class="folio-stepper-input" aria-describedby="factor-unit">'
            . '<span class="folio-stepper-unit" id="factor-unit">m³</span>'
            . '<button type="button" class="folio-stepper-button" data-folio-step="1" aria-label="Increase Faktor" title="Increase Faktor">\+</button></div>~',
            $html,
        );
    }

    public function test_without_unit_or_bounds(): void
    {
        $html = (new NumberFieldType())->renderEditor(new FieldContext(name: 'qty'));
        $this->assertStringContainsString('<div class="folio-stepper" data-folio-stepper>', $html);
        $this->assertStringNotContainsString('folio-stepper-unit', $html);
        $this->assertStringContainsString('aria-label="Decrease Qty"', $html, 'the humanized name names the buttons');
        $this->assertStringNotContainsString('aria-describedby', $html);
    }

    public function test_a_between_rule_sets_both_bounds(): void
    {
        $html = (new NumberFieldType())->renderEditor(new FieldContext(name: 'qty', validate: ['between:-5,2.5']));
        $this->assertStringContainsString('data-folio-stepper data-min="-5" data-max="2.5"', $html);
    }

    public function test_the_unit_must_be_a_short_text(): void
    {
        foreach ([['unit' => ''], ['unit' => 12], ['unit' => str_repeat('x', 13)], ['unit' => "m\n3"], ['unit' => ['en' => 'm']]] as $config) {
            try {
                (new NumberFieldType())->renderEditor(new FieldContext(name: 'qty', config: $config));
                $this->fail('accepted ' . json_encode($config));
            } catch (\LogicException $e) {
                $this->assertSame('Number field option "unit" must be a short text (1 to 12 characters, one line).', $e->getMessage());
            }
        }
        $this->assertStringContainsString('>CHF/m²</span>', (new NumberFieldType())->renderEditor(new FieldContext(name: 'qty', config: ['unit' => 'CHF/m²'])));
    }

    public function test_an_invalid_unit_is_a_boot_error(): void
    {
        foreach (['number', 'int', 'float', 'integer'] as $type) {
            $dir = sys_get_temp_dir() . '/folio_unit_' . bin2hex(random_bytes(4));
            mkdir($dir);
            file_put_contents($dir . '/thing.json', (string) json_encode(['key' => 'thing', 'fields' => ['qty' => ['type' => $type, 'unit' => '']]]));
            try {
                (new TypeCatalog($dir))->all();
                $this->fail("{$type}: an empty unit was accepted");
            } catch (FrontendConfigException $e) {
                $this->assertSame('Type "thing": field "qty": Number field option "unit" must be a short text (1 to 12 characters, one line).', $e->getMessage());
            } finally {
                unlink($dir . '/thing.json');
                rmdir($dir);
            }
        }
    }
}
