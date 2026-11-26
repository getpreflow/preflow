<?php

declare(strict_types=1);

namespace Preflow\Validation\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Validation\Message;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\Rules\Phone;
use Preflow\Validation\ValidationContext;

/** The phone rule (0.91): a number as people type it; dialable() is its form for tel:. */
final class PhoneRuleTest extends TestCase
{
    private function check(mixed $value): true|Message
    {
        return (new Phone())->validate($value, new ValidationContext('f', []));
    }

    public function test_accepts_numbers_as_people_type_them(): void
    {
        foreach (['+41 44 123 45 67', '044 123 45 67', '(044) 123-45-67', '+1 (555) 010-9999', '0041.44.123.45.67', '+49 30/1234567', "+41\u{00A0}44 123 45 67", "+41\u{202F}44 123 45 67", '12345', '112', '1414'] as $value) {
            $this->assertTrue($this->check($value), $value);
        }
    }

    public function test_an_empty_value_passes_so_required_decides(): void
    {
        $this->assertTrue($this->check(null));
        $this->assertTrue($this->check(''));
    }

    public function test_refuses_what_is_no_phone_number(): void
    {
        foreach (['call me', '+41 44 abc', '12', "+41 44\n123 45 67", "+41\t44 123 45 67", '++41 44 123', '41+44', '+', '1234567890123456', str_repeat('1', 40), ['+41'], 4144] as $value) {
            $message = $this->check($value);
            $this->assertInstanceOf(Message::class, $message, var_export($value, true));
            $this->assertSame('validation.phone', $message->key);
        }
        $this->assertStringContainsString('e.g. +41 44 123 45 67', (string) $this->check('call me'), 'the message says what to type instead');
    }

    public function test_dialable_keeps_the_plus_and_the_digits(): void
    {
        $this->assertSame('+41441234567', Phone::dialable('+41 44 123 45 67'));
        $this->assertSame('0441234567', Phone::dialable('(044) 123-45-67'));
        $this->assertSame('+49301234567', Phone::dialable('+49 30/1234567'));
        $this->assertNull(Phone::dialable('call me'));
        $this->assertNull(Phone::dialable(''));
    }

    public function test_the_alias_is_phone(): void
    {
        $this->assertInstanceOf(Phone::class, (new RuleFactory())->resolve('phone'));
    }
}
