<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Field;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Content\FrontendConfigException;
use Preflow\Folio\Content\ListQuery;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\HasColumnKind;
use Preflow\Folio\Field\SearchableText;
use Preflow\Folio\Field\StructuredFieldType;
use Preflow\Folio\Field\Types\DateRangeFieldType;
use Preflow\Folio\Field\Types\DateTimeFieldType;
use Preflow\Folio\Field\Types\TimeFieldType;
use Preflow\Folio\Tests\Support\TempRoots;

/**
 * The datetime, time and daterange inputs (0.91): the values, checks and storage are preflow/fields';
 * Folio draws the editor, converts what was posted from the field's zone, and shows the value.
 * The site zone is set here (Europe/Zurich), never read from the machine.
 */
final class DateTimeFieldTypesTest extends TestCase
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

    public function test_capabilities_and_no_rules_of_their_own(): void
    {
        foreach ([new DateTimeFieldType(), new TimeFieldType(), new DateRangeFieldType()] as $type) {
            $this->assertSame([], $type->rules(['with_time' => true]), $type->key() . ': the core\'s check applies in Folio, this adds none');
            $this->assertNotInstanceOf(StructuredFieldType::class, $type);
            $this->assertNotInstanceOf(SearchableText::class, $type);
            $this->assertNotInstanceOf(HasColumnKind::class, $type, $type->key() . ': the core registration owns the column kind');
        }
        $this->assertContains('datetime', ListQuery::LIST_TYPES);
        $this->assertContains('time', ListQuery::LIST_TYPES);
    }

    public function test_a_range_column_has_no_sort(): void
    {
        $this->assertTrue(ListQuery::sortable('datetime'));
        $this->assertTrue(ListQuery::sortable('string'));
        $this->assertFalse(ListQuery::sortable('daterange'));
        $this->assertFalse(ListQuery::sortable('relation'));
    }

    public function test_datetime_converts_what_was_typed_in_the_zone_to_utc(): void
    {
        $type = new DateTimeFieldType();

        $this->assertSame('2026-07-15T10:30:00Z', $type->normalizeInput('2026-07-15T12:30', []), 'site zone, summer');
        $this->assertSame('2026-01-15T11:30:00Z', $type->normalizeInput(' 2026-01-15T12:30 ', []), 'site zone, winter');
        $this->assertSame('2026-07-15T03:30:00Z', $type->normalizeInput('2026-07-15T12:30', ['zone' => 'Asia/Tokyo']), 'the field\'s zone');
        $this->assertSame('2026-07-15T10:30:00Z', $type->normalizeInput('2026-07-15 12:30', []));
        $this->assertSame('2026-10-25T00:30:00Z', $type->normalizeInput('2026-10-25T02:30', []), 'ambiguous: the earlier instant');
        $this->assertSame('2026-03-29T02:30', $type->normalizeInput('2026-03-29T02:30', []), 'a skipped time stays as typed: the check refuses it');
        $this->assertSame('soon', $type->normalizeInput('soon', []));
        $this->assertNull($type->normalizeInput('', []));
        $this->assertNull($type->normalizeInput('  ', []));
        $this->assertNull($type->normalizeInput(['x'], []));

        $this->assertSame('2026-07-15T10:30:00Z', $type->toStorage('2026-07-15T12:30:00+02:00'));
        $this->assertSame('2026-03-29T02:30', $type->toStorage('2026-03-29T02:30'), 'kept for the check');
        $this->assertNull($type->toStorage(null));
        $this->assertNull($type->toStorage(' '));
    }

    public function test_datetime_editor_shows_the_value_in_the_zone_and_names_it(): void
    {
        $type = new DateTimeFieldType();
        $html = $type->renderEditor(new FieldContext('starts_at', label: 'Starts', value: '2026-07-15T10:30:00Z'));

        $this->assertStringContainsString('type="datetime-local"', $html);
        $this->assertStringContainsString('name="starts_at"', $html);
        $this->assertStringContainsString('value="2026-07-15T12:30"', $html, 'the editor reads the site zone, not the machine\'s and not UTC');
        $this->assertStringContainsString('data-zone="Europe/Zurich">Europe/Zurich</p>', $html);
        $this->assertStringContainsString('data-folio-date', $html);

        $tokyo = $type->renderEditor(new FieldContext('starts_at', value: '2026-07-15T10:30:00Z', config: ['zone' => 'Asia/Tokyo']));
        $this->assertStringContainsString('value="2026-07-15T19:30"', $tokyo);
        $this->assertStringContainsString('>Asia/Tokyo</p>', $tokyo);

        $refused = $type->renderEditor(new FieldContext('starts_at', value: '2026-03-29T02:30', errors: ['nope']));
        $this->assertStringContainsString('value="2026-03-29T02:30"', $refused, 'what was typed comes back with the error');
        $this->assertStringContainsString('<input', $type->renderEditor(new FieldContext('starts_at', value: null)));
        $this->assertSame(['fields.js'], $type->assets());
    }

    public function test_datetime_output(): void
    {
        $type = new DateTimeFieldType();

        $this->assertSame('<time datetime="2026-07-15T10:30:00Z">2026-07-15 12:30</time>', $type->renderFrontend('2026-07-15T10:30:00Z', []));
        $this->assertSame('<time datetime="2026-07-15T10:30:00Z">2026-07-15 19:30</time>', $type->renderFrontend('2026-07-15T10:30:00Z', ['zone' => 'Asia/Tokyo']));
        $this->assertSame('', $type->renderFrontend(null, []));
        $this->assertSame('', $type->renderFrontend('junk', []));

        $this->assertMatchesRegularExpression('/2026.*12[:.]30/u', $type->renderAdmin('2026-07-15T10:30:00Z', []), 'the time in the site zone');
        $this->assertMatchesRegularExpression('/2026.*19[:.]30/u', $type->renderAdmin('2026-07-15T10:30:00Z', ['zone' => 'Asia/Tokyo']), 'and in the field\'s zone');
        $this->assertSame('&lt;b&gt;', $type->renderAdmin('<b>', []), 'text that is no date and time is shown escaped');
    }

    public function test_time(): void
    {
        $type = new TimeFieldType();

        $this->assertSame('09:05', $type->normalizeInput('9:05', []));
        $this->assertSame('09:05', $type->normalizeInput('09:05:30', []));
        $this->assertSame('25:00', $type->normalizeInput('25:00', []), 'kept for the check');
        $this->assertNull($type->normalizeInput('', []));
        $this->assertSame('09:05', $type->toStorage('09:05'));
        $this->assertNull($type->toStorage(null));
        $html = $type->renderEditor(new FieldContext('opens', label: 'Opens', value: '09:05'));
        $this->assertStringContainsString('type="time"', $html);
        $this->assertStringContainsString('value="09:05"', $html);
        $this->assertSame('<time>09:05</time>', $type->renderFrontend('09:05', []));
        $this->assertSame('', $type->renderFrontend(null, []));
        $this->assertSame('09:05', $type->renderAdmin('09:05', []));
    }

    public function test_daterange_dates(): void
    {
        $type = new DateRangeFieldType();

        $this->assertSame(['start' => '2026-03-01', 'end' => '2026-03-07'], $type->normalizeInput(['start' => ' 2026-03-01 ', 'end' => '2026-03-07'], []));
        $this->assertSame(['start' => '2026-03-01', 'end' => null], $type->normalizeInput(['start' => '2026-03-01', 'end' => ''], []), 'one end: the check refuses it');
        $this->assertNull($type->normalizeInput(['start' => '', 'end' => ''], []));
        $this->assertNull($type->normalizeInput('x', []));
        $this->assertNull($type->toStorage(null));

        $html = $type->renderEditor(new FieldContext('stay', label: 'Stay', value: ['start' => '2026-03-01', 'end' => '2026-03-07']));
        $this->assertStringContainsString('<legend>Stay</legend>', $html);
        $this->assertStringContainsString('<input type="date" id="folio-daterange-stay-start" name="stay[start]" value="2026-03-01">', $html);
        $this->assertStringContainsString('<input type="date" id="folio-daterange-stay-end" name="stay[end]" value="2026-03-07">', $html);
        $this->assertStringContainsString('>From</label>', $html);
        $this->assertStringContainsString('>To</label>', $html);
        $this->assertStringNotContainsString('Europe/Zurich', $html, 'dates have no zone');

        $this->assertSame('<time datetime="2026-03-01">2026-03-01</time> – <time datetime="2026-03-07">2026-03-07</time>', $type->renderFrontend(['start' => '2026-03-01', 'end' => '2026-03-07'], []));
        $this->assertSame('', $type->renderFrontend(['start' => '2026-03-07', 'end' => '2026-03-01'], []), 'an invalid range shows nothing');
        $this->assertSame('', $type->renderAdmin(null, []));
        $this->assertStringContainsString(' – ', $type->renderAdmin(['start' => '2026-03-01', 'end' => '2026-03-07'], []));
    }

    public function test_daterange_with_time_converts_from_the_zone(): void
    {
        $type = new DateRangeFieldType();
        $config = ['with_time' => true];

        $this->assertSame(
            ['start' => '2026-07-15T07:00:00Z', 'end' => '2026-07-15T15:30:00Z'],
            $type->normalizeInput(['start' => '2026-07-15T09:00', 'end' => '2026-07-15T17:30'], $config),
        );
        $this->assertSame(
            ['start' => '2026-03-29T02:30', 'end' => '2026-03-29T05:00:00Z'],
            $type->normalizeInput(['start' => '2026-03-29T02:30', 'end' => '2026-03-29T07:00'], $config),
            'a skipped time stays as typed: the check refuses it',
        );

        $html = $type->renderEditor(new FieldContext('slot', value: ['start' => '2026-07-15T07:00:00Z', 'end' => '2026-07-15T15:30:00Z'], config: $config));
        $this->assertStringContainsString('<input type="datetime-local" id="folio-daterange-slot-start" name="slot[start]" value="2026-07-15T09:00" aria-describedby="folio-daterange-slot-start-hint">', $html);
        $this->assertStringContainsString('name="slot[end]" value="2026-07-15T17:30"', $html);
        $this->assertStringContainsString('data-folio-date-hint data-zone="Europe/Zurich">Europe/Zurich</p>', $html, 'each end has the relative hint, in the field\'s zone');
        $this->assertSame(2, substr_count($html, 'data-folio-date>'));
        $this->assertStringNotContainsString('data-zone', (new DateRangeFieldType())->renderEditor(new FieldContext('stay', value: null)), 'dates have no zone');
        $this->assertSame(['fields.js'], $type->assets());
        $this->assertSame(
            '<time datetime="2026-07-15T07:00:00Z">2026-07-15 09:00</time> – <time datetime="2026-07-15T15:30:00Z">2026-07-15 17:30</time>',
            $type->renderFrontend(['start' => '2026-07-15T07:00:00Z', 'end' => '2026-07-15T15:30:00Z'], $config),
        );
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function bootMistakes(): iterable
    {
        yield 'datetime zone' => [['type' => 'datetime', 'zone' => 'Mars/Olympus'], 'Type "thing": field "f": "zone" is "Mars/Olympus"; use a time zone name such as "Europe/Zurich"'];
        yield 'datetime translatable' => [['type' => 'datetime', 'translatable' => true], 'Type "thing": field "f": A datetime field cannot be translatable'];
        yield 'time zone' => [['type' => 'time', 'zone' => 'UTC'], 'Type "thing": field "f": A time field holds a time of day without a zone: remove "zone".'];
        yield 'daterange zone without time' => [['type' => 'daterange', 'zone' => 'UTC'], 'Type "thing": field "f": "zone" belongs to a daterange of date-times'];
        yield 'datetime with_time' => [['type' => 'datetime', 'with_time' => true], 'Type "thing": field "f": "with_time" belongs to a daterange field'];
        yield 'nested datetime options' => [['type' => 'datetime', 'datetime' => ['zone' => 'UTC']], 'Type "thing": field "f": "datetime" holds options, but a datetime field takes them at its top level, next to "type": move "zone" out of "datetime".'];
        yield 'nested daterange options' => [['type' => 'daterange', 'daterange' => ['with_time' => true]], 'Type "thing": field "f": "daterange" holds options, but a daterange field takes them at its top level, next to "type": move "zone", "with_time" out of "daterange".'];
    }

    /** @param array<string, mixed> $field */
    #[DataProvider('bootMistakes')]
    public function test_config_mistakes_are_boot_errors_naming_the_type_and_field(array $field, string $message): void
    {
        $dir = TempRoots::path('folio_dates_boot_');
        mkdir($dir);
        file_put_contents($dir . '/thing.json', (string) json_encode(['key' => 'thing', 'fields' => ['f' => $field]]));
        try {
            (new TypeCatalog($dir))->all();
            $this->fail('accepted: ' . json_encode($field));
        } catch (FrontendConfigException $e) {
            $this->assertStringStartsWith($message, $e->getMessage());
        } finally {
            unlink($dir . '/thing.json');
            rmdir($dir);
        }
    }

    public function test_a_valid_config_boots(): void
    {
        $dir = TempRoots::path('folio_dates_ok_');
        mkdir($dir);
        file_put_contents($dir . '/thing.json', (string) json_encode(['key' => 'thing', 'fields' => [
            'a' => ['type' => 'datetime'], 'b' => ['type' => 'datetime', 'zone' => 'Europe/Zurich'], 'c' => ['type' => 'time'],
            'd' => ['type' => 'daterange'], 'e' => ['type' => 'daterange', 'with_time' => true, 'zone' => 'UTC'],
        ]]));
        try {
            $this->assertCount(1, (new TypeCatalog($dir))->all());
        } finally {
            unlink($dir . '/thing.json');
            rmdir($dir);
        }
    }
}
