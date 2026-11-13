<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Field;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\Types\TextFieldType;

/** The text field (board LibFields): the "58 / 160" counter beside the label when a maximum exists. */
final class TextFieldTypeTest extends TestCase
{
    public function test_no_maximum_no_counter(): void
    {
        $html = (new TextFieldType())->renderEditor(new FieldContext(name: 'body', value: 'Hi'));
        $this->assertStringNotContainsString('folio-counter', $html);
    }

    public function test_the_counter_reads_its_maximum_from_a_max_rule_or_the_length(): void
    {
        $value = str_repeat('a', 58);
        foreach ([[['required', 'max:160'], null], [[], 160], [['max:200'], 160], [['max:160'], 300]] as [$validate, $length]) {
            $html = (new TextFieldType())->renderEditor(new FieldContext(name: 'summary', label: 'Summary', value: $value, validate: $validate, length: $length));
            $this->assertMatchesRegularExpression(
                '~<div class="folio-label-row"><label for="summary">Summary</label><span class="folio-counter" id="summary-counter" data-folio-counter data-max="160" data-text="[^"]*">58 / 160</span></div>~',
                $html,
                json_encode([$validate, $length]),
            );
            $this->assertStringContainsString('aria-describedby="summary-counter"', $html);
            $this->assertStringNotContainsString('aria-live', $html, 'far from the limit: not announced');
        }
    }

    public function test_near_the_limit_the_counter_is_announced_and_over_it_marked(): void
    {
        $near = (new TextFieldType())->renderEditor(new FieldContext(name: 's', value: str_repeat('ä', 150), validate: ['max:160']));
        $this->assertMatchesRegularExpression('~<span class="folio-counter is-near" id="s-counter" data-folio-counter data-max="160" data-text="[^"]*" aria-live="polite">150 / 160</span>~', $near, 'counted in characters, not bytes');

        $over = (new TextFieldType())->renderEditor(new FieldContext(name: 's', value: str_repeat('a', 170), validate: ['max:160']));
        $this->assertStringContainsString('<span class="folio-counter is-near is-over"', $over);
    }

    public function test_a_line_break_counts_once(): void
    {
        $html = (new TextFieldType())->renderEditor(new FieldContext(name: 's', value: "ab\r\ncd", validate: ['max:10']));
        $this->assertStringContainsString('>5 / 10</span>', $html);
    }

    public function test_monospace_and_the_counter_together(): void
    {
        $html = (new TextFieldType())->renderEditor(new FieldContext(name: 'code', value: '', config: ['monospace' => true], validate: ['max:20']));
        $this->assertStringContainsString('class="folio-input-mono"', $html);
        $this->assertStringContainsString('>0 / 20</span>', $html);
    }
}
