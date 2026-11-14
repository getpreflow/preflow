<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\I18n\OverrideRefusal;
use Preflow\I18n\OverrideRules;
use Preflow\I18n\OverrideScope;

final class OverrideRulesTest extends TestCase
{
    private function rules(): OverrideRules
    {
        return new OverrideRules(new class implements OverrideScope {
            public function locales(string $group): array
            {
                return ['de', 'fr'];
            }

            public function editable(string $key, string $locale): bool
            {
                return str_starts_with($key, 'mail.') || str_starts_with($key, 'ui.');
            }

            public function placeholders(string $key, string $locale): ?array
            {
                return str_starts_with($key, 'mail.') ? ['link', 'name'] : null;
            }
        });
    }

    private function reason(?OverrideRefusal $refusal): ?string
    {
        return $refusal?->reason;
    }

    public function test_the_locale_and_key_must_be_offered(): void
    {
        $this->assertSame(OverrideRules::NOT_OFFERED, $this->reason($this->rules()->check('it', 'ui.save', 'Salva')));
        $this->assertSame(OverrideRules::NOT_OFFERED, $this->reason($this->rules()->check('../x', 'ui.save', 'x')));
        $this->assertSame(OverrideRules::NOT_EDITABLE, $this->reason($this->rules()->check('de', 'other.save', 'x')));
        $this->assertNull($this->rules()->check('de', 'ui.save', 'Sichern'));
    }

    public function test_removing_an_override_still_needs_an_offered_key(): void
    {
        $this->assertNull($this->rules()->check('de', 'mail.a.b', null));
        $this->assertSame(OverrideRules::NOT_EDITABLE, $this->reason($this->rules()->check('de', 'other.save', null)));
    }

    public function test_length_and_plain_text(): void
    {
        $long = $this->rules()->check('de', 'ui.save', str_repeat('a', OverrideRules::MAX_LENGTH + 1));
        $this->assertSame(OverrideRules::TOO_LONG, $long?->reason);
        $this->assertSame(['max' => OverrideRules::MAX_LENGTH], $long->params);
        foreach (['<b>x', 'a "b"', 'x`', '&#60;', 'Save &amp; close', '   '] as $text) {
            $this->assertSame(OverrideRules::NO_MARKUP, $this->reason($this->rules()->check('de', 'ui.save', $text)), $text);
        }
    }

    public function test_placeholders_must_match_the_source(): void
    {
        $missing = $this->rules()->check('de', 'mail.a.b', 'Hallo :name');
        $this->assertSame(OverrideRules::PLACEHOLDER_MISSING, $missing?->reason);
        $this->assertSame(':link', $missing->params['placeholder']);
        $unknown = $this->rules()->check('de', 'mail.a.b', ':link :name :extra');
        $this->assertSame(OverrideRules::PLACEHOLDER_UNKNOWN, $unknown?->reason);
        $this->assertSame(':extra', $unknown->params['placeholder']);
        $this->assertNull($this->rules()->check('de', 'mail.a.b', 'Dein :name: :link'));
        $this->assertNull($this->rules()->check('de', 'ui.save', 'Any :thing goes'), 'a group nobody fills in');
    }

    public function test_without_a_scope_only_locale_length_and_plain_text_apply(): void
    {
        $rules = new OverrideRules();
        $this->assertNull($rules->check('de', 'any.key', 'Hallo'));
        $this->assertSame(OverrideRules::NOT_OFFERED, $this->reason($rules->check('deu', 'any.key', 'Hallo')));
        $this->assertSame(OverrideRules::NO_MARKUP, $this->reason($rules->check('de', 'any.key', '<i>')));
    }

    public function test_a_text_splits_into_text_and_placeholders(): void
    {
        $this->assertSame([
            ['text' => 'Use at least ', 'placeholder' => false],
            ['text' => ':min', 'placeholder' => true],
            ['text' => ' characters, ', 'placeholder' => false],
            ['text' => ':min_x', 'placeholder' => true],
            ['text' => '.', 'placeholder' => false],
        ], OverrideRules::placeholderParts('Use at least :min characters, :min_x.'));
        $this->assertSame([['text' => ':a', 'placeholder' => true], ['text' => ':b', 'placeholder' => true]], OverrideRules::placeholderParts(':a:b'));
        $this->assertSame([['text' => 'Plain: no 1 placeholder', 'placeholder' => false]], OverrideRules::placeholderParts('Plain: no 1 placeholder'), 'a colon alone is text');
        $this->assertSame([], OverrideRules::placeholderParts(''));
    }
}
