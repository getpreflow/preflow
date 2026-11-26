<?php

declare(strict_types=1);

namespace Preflow\Fields\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Fields\PhoneOptions;
use Preflow\Fields\Rules\ValidPhone;
use Preflow\Fields\Values\Phone;
use Preflow\Validation\Message;
use Preflow\Validation\ValidationContext;

/** The phone field (0.91): the number as typed with its E.164 form, and its check. */
final class PhoneTest extends TestCase
{
    /** @return iterable<string, array{string, ?string, ?string}> typed, region, E.164 */
    public static function numbers(): iterable
    {
        yield 'with + and spaces' => ['+41 44 123 45 67', null, '+41441234567'];
        yield 'with 00' => ['0041 44 123 45 67', null, '+41441234567'];
        yield '(0) after the country code, CH' => ['+41 (0)44 123 45 67', null, '+41441234567'];
        yield '(0) after 00, CH' => ['0041 (0)44 123 45 67', null, '+41441234567'];
        yield '(0) after the country code, DE' => ['+49 (0)30 1234567', null, '+49301234567'];
        yield '(0) with spaces, AT' => ['+43 (0) 1 234 5678', null, '+4312345678'];
        yield '(0) after the country code, GB' => ['+44 (0)20 7946 0958', null, '+442079460958'];
        yield 'a bare 0 after + stays (Italy)' => ['+39 06 1234 5678', null, '+390612345678'];
        yield 'a (0) later in the number stays as typed' => ['+41 44 (0) 123 45 67', null, '+414401234567'];
        yield 'national, CH' => ['044 123 45 67', 'CH', '+41441234567'];
        yield 'national in brackets, DE' => ['(030) 1234567', 'DE', '+49301234567'];
        yield 'a lower-case region' => ['044 123 45 67', 'ch', '+41441234567'];
        yield 'Italy keeps its zero' => ['06 1234 5678', 'IT', '+390612345678'];
        yield 'LI has no trunk zero' => ['0 232 12 34', 'LI', '+42302321234'];
        yield 'no trunk zero, ES' => ['612 345 678', 'ES', '+34612345678'];
        yield 'US 10 digits' => ['(555) 010-9999', 'US', '+15550109999'];
        yield 'US with the 1' => ['1 555 010 9999', 'US', '+15550109999'];
        yield 'non-breaking spaces' => ["+41\u{00A0}44\u{202F}123 45 67", null, '+41441234567'];
        yield 'too few digits after +' => ['+123', null, null];
        yield 'too few digits after 00' => ['001', null, null];
        yield 'six digits after + are too few' => ['+41 44 12', null, null];
        yield 'a service number has no E.164 form' => ['112', 'CH', null];
        yield 'a service number without a region' => ['1414', null, null];
        yield 'national without a region' => ['044 123 45 67', null, null];
        yield 'national with a region this table lacks' => ['044 123 45 67', 'ZZ', null];
        yield 'too long with the country code' => ['012345678901234', 'CH', null];
        yield 'not a number' => ['call me', 'CH', null];
    }

    #[DataProvider('numbers')]
    public function test_e164(string $typed, ?string $region, ?string $expected): void
    {
        $this->assertSame($expected, Phone::e164($typed, $region));
    }

    public function test_the_problem_says_why_a_number_cannot_be_used(): void
    {
        $this->assertNull(Phone::problem('+41 44 123 45 67'));
        $this->assertNull(Phone::problem('044 123 45 67', 'CH'));
        $this->assertNull(Phone::problem('112'), 'a service number is fine without an E.164 form');
        $this->assertSame('validation.phone_region', Phone::problem('044 123 45 67'));
        $this->assertSame('validation.phone_region', Phone::problem('044 123 45 67', 'ZZ'));
        $this->assertSame('validation.phone', Phone::problem('call me', 'CH'));
        $this->assertSame('validation.phone', Phone::problem('012345678901234', 'CH'));
        $this->assertSame('validation.phone', Phone::problem('00' . str_repeat('1', 16)));
        $this->assertSame('validation.phone', Phone::problem('+123'), 'service numbers are written without +');
        $this->assertSame('validation.phone', Phone::problem('0041 44'));
        $this->assertNull(Phone::problem('+41 44 123 4'), 'enough digits after +');
    }

    public function test_the_stored_shape_has_both_keys_and_reads_back(): void
    {
        $phone = Phone::of(' 044 123 45 67 ', 'CH');

        $this->assertSame(['as_typed' => '044 123 45 67', 'e164' => '+41441234567'], $phone->toStored());
        $this->assertEquals($phone, Phone::fromStored($phone->toStored()));
        $this->assertEquals($phone, Phone::fromStored(json_encode($phone->toStored())), 'JSON text as SQL storage gives it');
        $this->assertSame(['as_typed' => '112', 'e164' => null], Phone::of('112', 'CH')->toStored());
    }

    public function test_reading_never_fails(): void
    {
        foreach ([null, '', '  ', [], '{}', '{"as_typed": ""}', '{"e164": "+41"}', 5, ['as_typed' => 5]] as $value) {
            $this->assertNull(Phone::fromStored($value), json_encode($value) ?: '');
        }
        $this->assertEquals(new Phone('+41 44 1', '+4144 1'), Phone::fromStored(['as_typed' => '+41 44 1', 'e164' => '+4144 1']));
        $this->assertSame('+41441234567', Phone::fromStored('+41 44 123 45 67')?->e164, 'plain text with a + start');
        $this->assertNull(Phone::fromStored('044 123 45 67')?->e164, 'plain text national: no region to read it with');
    }

    public function test_the_tel_address_is_the_e164_form_else_the_digits(): void
    {
        $this->assertSame('tel:+41441234567', Phone::of('044 123 45 67', 'CH')->href());
        $this->assertSame('tel:0441234567', (new Phone('044 123 4567'))->href(), 'no E.164: the digits as typed');
        $this->assertSame('tel:112', Phone::of('112', 'CH')->href());
        $this->assertNull((new Phone('call me'))->href());
    }

    public function test_an_empty_number_cannot_be_made(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Phone('  ');
    }

    /** @param array<string, mixed> $config */
    private function check(mixed $value, array $config = []): true|Message
    {
        return (new ValidPhone(PhoneOptions::fromConfig($config)))->validate($value, new ValidationContext('phone', []));
    }

    public function test_the_check_accepts_what_can_be_normalized(): void
    {
        $this->assertTrue($this->check(['as_typed' => '+41 44 123 45 67', 'e164' => '+41441234567']));
        $this->assertTrue($this->check('{"as_typed":"044 123 45 67","e164":"+41441234567"}', ['default_region' => 'CH']));
        $this->assertTrue($this->check(['as_typed' => '044 123 45 67', 'e164' => null], ['default_region' => 'CH']));
        $this->assertTrue($this->check('044 123 45 67', ['default_region' => 'CH']), 'plain number text');
        $this->assertTrue($this->check(['as_typed' => '112', 'e164' => null]));
        foreach ([null, '', []] as $empty) {
            $this->assertTrue($this->check($empty));
        }
    }

    public function test_the_check_refuses_with_a_message_that_says_what_to_do(): void
    {
        $region = $this->check(['as_typed' => '044 123 45 67', 'e164' => null]);
        $this->assertInstanceOf(Message::class, $region);
        $this->assertSame('Cannot tell which country this number belongs to: start it with + and the country code, e.g. +41 44 123 45 67.', (string) $region);

        $this->assertSame('Must be a phone number: digits, optionally with a leading + and spaces, e.g. +41 44 123 45 67.', (string) $this->check(['as_typed' => 'call me']));
        foreach ([['as_typed' => 5], ['e164' => '+41441234567'], ['as_typed' => '+41441234567', 'e164' => '0041'], ['as_typed' => '+41441234567', 'e164' => 5], 7] as $bad) {
            $message = $this->check($bad);
            $this->assertInstanceOf(Message::class, $message, json_encode($bad) ?: '');
            $this->assertSame('validation.phone', $message->key);
        }
    }

    public function test_a_stored_e164_must_be_what_the_number_normalizes_to(): void
    {
        $this->assertTrue($this->check(['as_typed' => '044 123 45 67', 'e164' => '+41441234567'], ['default_region' => 'CH']));
        $this->assertTrue($this->check(['as_typed' => '+41 44 123 45 67', 'e164' => '+41441234567']), 'international: no default region needed');
        $this->assertTrue($this->check(['as_typed' => '+41 (0)44 123 45 67', 'e164' => '+41441234567']));
        $this->assertTrue($this->check(['as_typed' => '112', 'e164' => null]));
        foreach ([
            [['as_typed' => '044 123 45 67', 'e164' => '+49441234567'], ['default_region' => 'CH']],
            [['as_typed' => '+41 44 123 45 67', 'e164' => '+41441234568'], []],
            [['as_typed' => '112', 'e164' => '+41112'], ['default_region' => 'CH']],
            [['as_typed' => '+41 (0)44 123 45 67', 'e164' => '+410441234567'], []],
        ] as [$value, $config]) {
            $message = $this->check($value, $config);
            $this->assertInstanceOf(Message::class, $message, json_encode($value) ?: '');
            $this->assertSame('validation.phone', $message->key);
        }
        // National without a region cannot be matched: still refused, naming the fix.
        $this->assertSame('validation.phone_region', $this->check(['as_typed' => '044 123 45 67', 'e164' => '+41441234567'])->key ?? null);
    }

    public function test_options_read_and_check_the_default_region(): void
    {
        $this->assertSame('CH', PhoneOptions::fromConfig(['default_region' => 'ch'])->defaultRegion);
        $this->assertNull(PhoneOptions::fromConfig([])->defaultRegion);
        PhoneOptions::check(['default_region' => 'DE']);
        PhoneOptions::check([]);

        $this->expectExceptionMessage('"default_region" is "Switzerland"; use one of CH, LI, DE');
        PhoneOptions::check(['default_region' => 'Switzerland']);
    }

    public function test_a_nested_phone_object_is_refused_naming_where_the_option_goes(): void
    {
        $this->expectExceptionMessage('"phone" holds options, but a phone field takes them at its top level, next to "type": move "default_region" out of "phone".');
        PhoneOptions::check(['phone' => ['default_region' => 'CH']]);
    }

    public function test_a_phone_field_cannot_be_translatable(): void
    {
        $this->expectExceptionMessage('A phone field cannot be translatable; it holds the same value in every language. Remove "translatable".');
        PhoneOptions::check(['translatable' => true]);
    }
}
