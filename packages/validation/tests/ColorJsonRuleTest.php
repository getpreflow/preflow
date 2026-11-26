<?php

declare(strict_types=1);

namespace Preflow\Validation\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Validation\Message;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\Rules\Color;
use Preflow\Validation\Rules\Json;
use Preflow\Validation\ValidationContext;

/** The color and json rules (0.91). */
final class ColorJsonRuleTest extends TestCase
{
    private function check(\Preflow\Validation\ValidationRule $rule, mixed $value): true|Message
    {
        return $rule->validate($value, new ValidationContext('f', []));
    }

    public function test_a_colour_is_six_or_eight_hex_digits_after_a_hash(): void
    {
        foreach (['#1a2b3c', '#1A2B3C', '#1a2b3c80', '#000000'] as $value) {
            $this->assertTrue($this->check(new Color(), $value), $value);
        }
        foreach (['1a2b3c', '#abc', '#1a2b3', '#1a2b3c8', '#1a2b3cdd0', '#gggggg', 'red', ' #1a2b3c', "#c00000\n", 123456, ['#1a2b3c']] as $value) {
            $message = $this->check(new Color(), $value);
            $this->assertInstanceOf(Message::class, $message, json_encode($value) ?: '');
            $this->assertSame('validation.color', $message->key);
        }
        $this->assertSame('Must be a colour in hex form, such as #1a2b3c (or #1a2b3c80 with transparency).', (string) $this->check(new Color(), 'red'));
    }

    public function test_a_palette_limits_the_colours_and_compares_them_as_colours(): void
    {
        $rule = new Color(['#C00000', '#00aa00']);
        $this->assertTrue($this->check($rule, '#c00000'));
        $this->assertTrue($this->check($rule, '#00AA00'));
        $message = $this->check($rule, '#123456');
        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame('Choose one of the colours offered: #C00000, #00aa00.', (string) $message);
        $this->assertSame('validation.color', $this->check($rule, 'nope')->key ?? null);
    }

    public function test_an_empty_value_passes_so_required_decides(): void
    {
        $this->assertTrue($this->check(new Color(['#ffffff']), null));
        $this->assertTrue($this->check(new Color(), ''));
        $this->assertTrue($this->check(new Json(), null));
        $this->assertTrue($this->check(new Json(), ''));
    }

    public function test_json_accepts_any_json_value_and_refuses_anything_else(): void
    {
        foreach (['{"a": 1}', '[1, 2]', '"text"', '12', 'null', 'true', "{\n  \"a\": [1]\n}"] as $value) {
            $this->assertTrue($this->check(new Json(), $value), $value);
        }
        foreach (['{a: 1}', "{'a': 1}", '[1, 2', 'text', '{"a": 1,}', 12, ['a' => 1]] as $value) {
            $message = $this->check(new Json(), $value);
            $this->assertInstanceOf(Message::class, $message, json_encode($value) ?: '');
            $this->assertSame('validation.json', $message->key);
        }
        $this->assertSame('Must be valid JSON, such as {"name": "value"} or [1, 2, 3].', (string) $this->check(new Json(), '{a: 1}'));
    }

    public function test_both_have_aliases(): void
    {
        $factory = new RuleFactory();
        $this->assertInstanceOf(Color::class, $factory->resolve('color'));
        $this->assertInstanceOf(Json::class, $factory->resolve('json'));
    }
}
