<?php

declare(strict_types=1);

namespace Preflow\Fields\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Fields\DateOptions;
use Preflow\Fields\Rules\ValidDateRange;
use Preflow\Fields\Rules\ValidDateTime;
use Preflow\Fields\Rules\ValidTime;
use Preflow\Fields\Values\DateRange;
use Preflow\Fields\Values\DateTimeValue;
use Preflow\Fields\Values\TimeOfDay;
use Preflow\Validation\Message;
use Preflow\Validation\ValidationContext;

/**
 * The datetime, time and daterange fields (0.91): the values, the zone rules around a clock change
 * (Europe/Zurich: 2026-03-29 02:00 jumps to 03:00, 2026-10-25 03:00 falls back to 02:00) and the checks.
 * No test reads the machine's zone or the date: the site zone is set here.
 */
final class DateTimeFieldsTest extends TestCase
{
    private string $previousZone;

    protected function setUp(): void
    {
        $this->previousZone = date_default_timezone_get();
        date_default_timezone_set('Europe/Zurich');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->previousZone);
    }

    private static function zone(string $name): \DateTimeZone
    {
        return new \DateTimeZone($name);
    }

    public function test_datetime_reads_in_site_zone_whatever_the_editor_zone(): void
    {
        $zurich = (new DateOptions())->zone();
        $this->assertSame('Europe/Zurich', $zurich->getName(), 'the site zone is app.timezone, which Application sets as the default');

        // Winter (+01:00) and summer (+02:00) in the site zone; the browser's own zone plays no part.
        $this->assertSame('2026-01-15T11:30:00Z', DateTimeValue::fromLocal('2026-01-15 12:30', $zurich)?->toStored());
        $this->assertSame('2026-07-15T10:30:00Z', DateTimeValue::fromLocal('2026-07-15T12:30', $zurich)?->toStored());
        $this->assertSame('2026-07-15T10:30:15Z', DateTimeValue::fromLocal('2026-07-15T12:30:15', $zurich)?->toStored(), 'seconds are kept');

        // Another field zone, whatever the site's.
        $tokyo = (new DateOptions('Asia/Tokyo'))->zone();
        $this->assertSame('2026-07-15T03:30:00Z', DateTimeValue::fromLocal('2026-07-15 12:30', $tokyo)?->toStored());

        // Read back in each zone.
        $value = DateTimeValue::fromStored('2026-07-15T10:30:00Z');
        $this->assertSame('2026-07-15 12:30', $value?->format('Y-m-d H:i', $zurich));
        $this->assertSame('2026-07-15 19:30', $value?->format('Y-m-d H:i', $tokyo));
        $this->assertSame('2026-07-15T12:30', $value?->inputValue($zurich));
    }

    public function test_a_time_the_clocks_skip_is_refused_naming_the_gap(): void
    {
        $zurich = self::zone('Europe/Zurich');

        $this->assertNull(DateTimeValue::fromLocal('2026-03-29 02:30', $zurich));
        $this->assertSame('gap', DateTimeValue::localProblem('2026-03-29 02:30', $zurich));
        $this->assertSame('gap', DateTimeValue::localProblem('2026-03-29T02:00', $zurich), 'the first skipped minute');
        $this->assertNull(DateTimeValue::localProblem('2026-03-29T01:59', $zurich));
        $this->assertNull(DateTimeValue::localProblem('2026-03-29T03:00', $zurich), 'the first minute after the gap');
        $this->assertSame('2026-03-29T01:00:00Z', DateTimeValue::fromLocal('2026-03-29T03:00', $zurich)?->toStored());
        $this->assertSame('invalid', DateTimeValue::localProblem('2026-02-30 10:00', $zurich));
        $this->assertSame('invalid', DateTimeValue::localProblem('next week', $zurich));

        $message = (new ValidDateTime())->validate('2026-03-29T02:30', new ValidationContext('x', []));
        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame('2026-03-29 02:30 does not exist in Europe/Zurich (clocks go forward). Choose another time.', $message->english());
    }

    public function test_an_ambiguous_time_takes_the_earlier_instant(): void
    {
        $zurich = self::zone('Europe/Zurich');

        // 02:30 happens at 00:30Z (+02:00, still summer time) and at 01:30Z (+01:00): the earlier one.
        $this->assertSame('2026-10-25T00:30:00Z', DateTimeValue::fromLocal('2026-10-25 02:30', $zurich)?->toStored());
        $this->assertNull(DateTimeValue::localProblem('2026-10-25 02:30', $zurich), 'it is a valid time');
        $this->assertSame('2026-10-25T02:00:00Z', DateTimeValue::fromLocal('2026-10-25 03:00', $zurich)?->toStored(), 'after the repeated hour: winter time');

        // A zone without clock changes has neither gap nor overlap.
        $this->assertSame('2026-03-29T02:30:00Z', DateTimeValue::fromLocal('2026-03-29 02:30', self::zone('UTC'))?->toStored());
        // A zone whose change is at another time (New York: 2026-03-08 02:00 to 03:00).
        $this->assertSame('gap', DateTimeValue::localProblem('2026-03-08 02:30', self::zone('America/New_York')));
        // A half-hour zone (Lord Howe skips 02:00 to 02:30).
        $this->assertSame('gap', DateTimeValue::localProblem('2026-10-04 02:15', self::zone('Australia/Lord_Howe')));
    }

    public function test_the_check_takes_the_stored_form_only(): void
    {
        $rule = new ValidDateTime();
        $context = new ValidationContext('x', []);
        $this->assertTrue($rule->validate(null, $context));
        $this->assertTrue($rule->validate('', $context));
        $this->assertTrue($rule->validate('2026-07-15T10:30:00Z', $context));
        foreach (['2026-07-15 12:30', '2026-07-15T10:30:00+02:00', '2026-07-15T10:30Z', '2026-02-30T10:30:00Z', '2026-07-15T25:30:00Z', 'soon', 5, ['a']] as $bad) {
            $message = $rule->validate($bad, $context);
            $this->assertInstanceOf(Message::class, $message, json_encode($bad) ?: '');
            $this->assertSame('Must be a date and time, typed as 2026-03-29 14:30 in the editor (stored in UTC as 2026-03-29T12:30:00Z).', $message->english());
        }
    }

    public function test_the_stored_form_reads_leniently_and_writes_strictly(): void
    {
        $this->assertSame('2026-07-15T10:30:00Z', DateTimeValue::fromStored('2026-07-15T12:30:00+02:00')?->toStored());
        $this->assertSame('2026-07-15T10:30:00Z', DateTimeValue::fromStored('2026-07-15T10:30:00.250Z')?->toStored());
        $this->assertNull(DateTimeValue::fromStored('2026-02-31T10:30:00Z'));
        $this->assertNull(DateTimeValue::fromStored(''));
        $this->assertNull(DateTimeValue::fromStored(null));
        $this->assertNull(DateTimeValue::fromStored('2026-07-15 12:30'));
    }

    public function test_time_of_day(): void
    {
        $this->assertSame('09:05', TimeOfDay::parse('9:05')?->toStored());
        $this->assertSame('09:05', TimeOfDay::parse('09:05:30')?->toStored());
        $this->assertSame('23:59', TimeOfDay::parse('23:59')?->toStored());
        $this->assertNull(TimeOfDay::parse('24:00'));
        $this->assertNull(TimeOfDay::parse('12:60'));
        $this->assertNull(TimeOfDay::parse('noon'));
        $this->assertNull(TimeOfDay::fromStored('9:05'), 'stored is exactly HH:MM');
        $this->assertSame('9:05 am', TimeOfDay::fromStored('09:05')?->format('g:i a'));

        $rule = new ValidTime();
        $context = new ValidationContext('x', []);
        $this->assertTrue($rule->validate('09:05', $context));
        $this->assertTrue($rule->validate(null, $context));
        foreach (['9:05', '24:00', '09:05:30', 'noon', 905] as $bad) {
            $message = $rule->validate($bad, $context);
            $this->assertInstanceOf(Message::class, $message, json_encode($bad) ?: '');
            $this->assertSame('Must be a time of day as hours and minutes, such as 14:30.', $message->english());
        }
    }

    /** @return iterable<string, array{mixed, bool, ?string}> */
    public static function ranges(): iterable
    {
        yield 'dates' => [['start' => '2026-03-01', 'end' => '2026-03-07'], false, null];
        yield 'one day' => [['start' => '2026-03-01', 'end' => '2026-03-01'], false, null];
        yield 'JSON text' => ['{"start":"2026-03-01","end":"2026-03-07"}', false, null];
        yield 'empty' => [null, false, null];
        yield 'both ends empty' => [['start' => '', 'end' => null], false, null];
        yield 'date-times' => [['start' => '2026-03-01T08:00:00Z', 'end' => '2026-03-01T08:00:01Z'], true, null];
        yield 'end before start' => [['start' => '2026-03-07', 'end' => '2026-03-01'], false, 'The end (2026-03-01) is before the start (2026-03-07). Choose an end on or after 2026-03-07.'];
        yield 'date-time end before start, shown in the zone' => [['start' => '2026-07-01T10:00:00Z', 'end' => '2026-07-01T09:00:00Z'], true, 'The end (2026-07-01 11:00) is before the start (2026-07-01 12:00). Choose an end on or after 2026-07-01 12:00.'];
        yield 'no end' => [['start' => '2026-03-01', 'end' => null], false, 'Must be a date range with a start and an end, such as 2026-03-01 – 2026-03-07.'];
        yield 'no start' => [['start' => '', 'end' => '2026-03-01'], false, 'Must be a date range with a start and an end, such as 2026-03-01 – 2026-03-07.'];
        yield 'not a date' => [['start' => '2026-02-30', 'end' => '2026-03-01'], false, 'Must be a date range with a start and an end, such as 2026-03-01 – 2026-03-07.'];
        yield 'a time in a date range' => [['start' => '2026-03-01T08:00:00Z', 'end' => '2026-03-02T08:00:00Z'], false, 'Must be a date range with a start and an end, such as 2026-03-01 – 2026-03-07.'];
        yield 'a date in a range with time' => [['start' => '2026-03-01', 'end' => '2026-03-02'], true, 'Must be a date range with a start and an end, such as 2026-03-01 09:00 – 2026-03-07 17:00.'];
        yield 'a list' => [['2026-03-01', '2026-03-02'], false, 'Must be a date range with a start and an end, such as 2026-03-01 – 2026-03-07.'];
        yield 'a text' => ['2026-03-01', false, 'Must be a date range with a start and an end, such as 2026-03-01 – 2026-03-07.'];
        yield 'broken JSON' => ['{"start": ', false, 'Must be a date range with a start and an end, such as 2026-03-01 – 2026-03-07.'];
        yield 'a skipped time' => [['start' => '2026-03-29T02:30', 'end' => '2026-03-29T05:00:00Z'], true, '2026-03-29 02:30 does not exist in Europe/Zurich (clocks go forward). Choose another time.'];
    }

    #[DataProvider('ranges')]
    public function test_the_range_check(mixed $value, bool $withTime, ?string $expected): void
    {
        $result = (new ValidDateRange(new DateOptions(withTime: $withTime)))->validate($value, new ValidationContext('x', []));

        if ($expected === null) {
            $this->assertTrue($result);
        } else {
            $this->assertInstanceOf(Message::class, $result);
            $this->assertSame($expected, $result->english());
        }
    }

    public function test_a_range_reads_and_formats(): void
    {
        $dates = DateRange::fromStored('{"start":"2026-03-01","end":"2026-03-07"}');
        $this->assertSame(['start' => '2026-03-01', 'end' => '2026-03-07'], $dates?->toStored());
        $this->assertFalse($dates?->hasTime());
        $this->assertSame('1 Mar', $dates?->format(false, self::zone('Pacific/Auckland'), 'j M'), 'a date is the same day in every zone');

        $times = DateRange::fromStored(['start' => '2026-07-01T07:00:00Z', 'end' => '2026-07-01T15:30:00Z']);
        $this->assertTrue($times?->hasTime());
        $this->assertSame('2026-07-01 09:00', $times?->format(false, self::zone('Europe/Zurich')));
        $this->assertSame('17:30', $times?->format(true, self::zone('Europe/Zurich'), 'H:i'));

        $this->assertNull(DateRange::fromStored(['start' => '2026-03-07', 'end' => '2026-03-01']), 'an invalid range reads as none');
        $this->assertNull(DateRange::fromStored(['start' => '2026-03-01', 'end' => '2026-03-01T10:00:00Z']), 'mixed kinds');
        $this->assertNull(DateRange::fromStored(null));
    }

    public function test_options_and_their_boot_checks(): void
    {
        $this->assertSame('Asia/Tokyo', DateOptions::fromConfig(['zone' => 'Asia/Tokyo'])->zone()->getName());
        $this->assertSame('Europe/Zurich', DateOptions::fromConfig(['zone' => 'Nowhere/Land'])->zone()->getName(), 'an unknown zone reads as the site zone (the boot check refuses it)');
        $this->assertTrue(DateOptions::fromConfig(['with_time' => true])->withTime);

        foreach ([
            [['datetime', ['zone' => 'Europe/Zurich', 'translatable' => false]], null],
            [['daterange', ['with_time' => true, 'zone' => 'UTC']], null],
            [['datetime', ['translatable' => true]], 'A datetime field cannot be translatable; it holds the same value in every language. Remove "translatable".'],
            [['time', ['translatable' => true]], 'A time field cannot be translatable; it holds the same value in every language. Remove "translatable".'],
            [['datetime', ['zone' => 'Mars/Olympus']], '"zone" is "Mars/Olympus"; use a time zone name such as "Europe/Zurich" or "UTC", or leave it out for the site\'s zone (app.timezone).'],
            [['datetime', ['zone' => 'UTC+2']], '"zone" is "UTC+2"; use a time zone name such as "Europe/Zurich" or "UTC", or leave it out for the site\'s zone (app.timezone).'],
            [['time', ['zone' => 'UTC']], 'A time field holds a time of day without a zone: remove "zone".'],
            [['daterange', ['zone' => 'UTC']], '"zone" belongs to a daterange of date-times: add "with_time": true, or remove "zone" (dates have no zone).'],
            [['datetime', ['with_time' => true]], '"with_time" belongs to a daterange field; a datetime field always holds a date and a time. Remove "with_time".'],
            [['time', ['with_time' => true]], '"with_time" belongs to a daterange field; a time field holds a time only. Remove "with_time".'],
            [['daterange', ['with_time' => 'yes']], '"with_time" must be true or false.'],
            [['datetime', ['datetime' => ['zone' => 'UTC']]], '"datetime" holds options, but a datetime field takes them at its top level, next to "type": move "zone" out of "datetime".'],
            [['daterange', ['daterange' => ['with_time' => true]]], '"daterange" holds options, but a daterange field takes them at its top level, next to "type": move "zone", "with_time" out of "daterange".'],
        ] as [[$type, $config], $message]) {
            if ($message === null) {
                DateOptions::check($type, $config);
                $this->addToAssertionCount(1);
                continue;
            }
            try {
                DateOptions::check($type, $config);
                $this->fail("{$type} " . json_encode($config) . ' was accepted');
            } catch (\LogicException $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }
    }
}
