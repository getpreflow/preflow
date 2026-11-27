<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Values;

use PHPUnit\Framework\TestCase;
use Preflow\Data\FieldKinds;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\Validation\StoredDate;
use Preflow\Data\Values\CoreValues;
use Preflow\Data\Values\Core\DateValue;
use Preflow\Data\Values\DescribesShapes;
use Preflow\Data\Values\FieldValue;
use Preflow\Data\Values\ListsReferences;
use Preflow\Validation\ValidationContext;

/**
 * The core types' field values (0.93): an input value to what saveType() stores, a stored value
 * (as a driver returns it, JSON text on SQL included) to a JSON-safe output value, and the output
 * shape's name. They convert and never refuse: what does not convert passes through for the rules.
 */
final class CoreValuesTest extends TestCase
{
    private string $tz;

    protected function setUp(): void
    {
        $this->tz = date_default_timezone_get();
        date_default_timezone_set('Europe/Zurich');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->tz);
    }

    private static function kinds(): FieldKinds
    {
        $kinds = new FieldKinds();
        CoreValues::register($kinds);

        return $kinds;
    }

    /** @param array<string, mixed> $config */
    private static function field(string $type, array $config = []): TypeFieldDefinition
    {
        return new TypeFieldDefinition('f', $type, config: $config);
    }

    /** @param array<string, mixed> $config */
    private static function value(string $type, array $config = []): FieldValue
    {
        return self::kinds()->valueOf(self::field($type, $config));
    }

    public function test_the_nine_core_values_are_registered(): void
    {
        $this->assertSame(
            ['string', 'text', 'number', 'boolean', 'date', 'select', 'tags', 'relation', 'asset'],
            array_keys(self::kinds()->values()),
        );
        foreach (self::kinds()->values() as $type => $value) {
            $this->assertInstanceOf(DescribesShapes::class, $value, $type);
            foreach (array_keys($value->shapes()) as $shape) {
                $this->assertArrayHasKey($shape, CoreValues::SHAPES, $type . ' describes ' . $shape . ' with the core descriptions');
            }
        }
    }

    public function test_text_to_stored_and_output(): void
    {
        foreach (['string', 'text'] as $type) {
            $value = self::value($type);
            $field = self::field($type);
            $this->assertSame('string', $value->shape($field));
            $this->assertSame('Hello', $value->toStored('Hello', $field));
            $this->assertSame('', $value->toStored('', $field));
            $this->assertSame('42', $value->toStored(42, $field));
            $this->assertNull($value->toStored(null, $field));
            $this->assertSame('Hello', $value->toOutput('Hello', $field));
            $this->assertSame('42', $value->toOutput(42, $field));
            $this->assertNull($value->toOutput(null, $field));
        }
    }

    public function test_number_to_stored_and_output(): void
    {
        $value = self::value('number');
        $field = self::field('number');

        $this->assertSame('number', $value->shape($field));
        $this->assertSame(3.5, $value->toStored('3.50', $field));
        $this->assertSame(12, $value->toStored('12', $field));
        $this->assertSame(-7, $value->toStored(' -7', $field));
        $this->assertSame(4.25, $value->toStored(4.25, $field));
        $this->assertNull($value->toStored('', $field));
        $this->assertNull($value->toStored(null, $field));
        $this->assertSame(3.5, $value->toOutput('3.50', $field), 'numeric text an SQL driver returns');
        $this->assertSame(12, $value->toOutput(12, $field));
        $this->assertNull($value->toOutput('', $field));
    }

    public function test_boolean_to_stored_and_output(): void
    {
        $value = self::value('boolean');
        $field = self::field('boolean');

        $this->assertSame('boolean', $value->shape($field));
        foreach (['1' => true, '0' => false, 'true' => true, 'false' => false, 'on' => true, 'TRUE' => true] as $in => $out) {
            $this->assertSame($out, $value->toStored((string) $in, $field), (string) $in);
        }
        $this->assertTrue($value->toStored(1, $field));
        $this->assertFalse($value->toStored(0, $field));
        $this->assertTrue($value->toStored(true, $field));
        $this->assertNull($value->toStored('', $field));
        $this->assertNull($value->toStored(null, $field));
        $this->assertTrue($value->toOutput('1', $field), 'SQLite returns its integer column as text');
        $this->assertFalse($value->toOutput(0, $field));
        $this->assertNull($value->toOutput(null, $field));
    }

    public function test_date_to_stored_and_output(): void
    {
        $value = self::value('date');
        $field = self::field('date');

        $this->assertSame('date', $value->shape($field));
        foreach (['2026-11-27', '2026-11-27T05:00', '2026-11-27T05:00:00+00:00'] as $date) {
            $this->assertSame($date, $value->toStored($date, $field), 'stored as given');
            $this->assertSame($date, $value->toOutput($date, $field));
        }
        $this->assertNull($value->toStored('', $field));
        $this->assertNull($value->toOutput(null, $field));
    }

    public function test_select_to_stored_and_output(): void
    {
        $value = self::value('select');
        $field = self::field('select', ['options' => ['a' => 'A', '2' => 'Two']]);

        $this->assertSame('string', $value->shape($field));
        $this->assertSame('a', $value->toStored('a', $field));
        $this->assertSame('2', $value->toStored(2, $field));
        $this->assertNull($value->toStored('', $field));
        $this->assertNull($value->toStored(null, $field));
        $this->assertSame('a', $value->toOutput('a', $field));
        $this->assertSame('2', $value->toOutput(2, $field));
    }

    public function test_tags_to_stored_and_output(): void
    {
        $value = self::value('tags');
        $field = self::field('tags');

        $this->assertSame('string_list', $value->shape($field));
        $this->assertSame(['a', 'b'], $value->toStored('a, b,A', $field), 'a comma string, case-folded duplicates dropped');
        $this->assertSame(['x', 'y'], $value->toStored('["x"," y"]', $field), 'JSON-list text');
        $this->assertSame(['Café', 'ab'], $value->toStored(["Cafe\u{0301}", "a\u{0007}b", ' ', 'café'], $field), 'NFC, control characters dropped');
        $this->assertNull($value->toStored([], $field), 'no tags is no value');
        $this->assertNull($value->toStored('', $field));
        $this->assertSame(['x', 'y'], $value->toOutput('["x","y"]', $field), 'JSON text an SQL driver returns');
        $this->assertSame(['a', 'b'], $value->toOutput('a, b', $field), 'a legacy comma string');
        $this->assertSame(['a'], $value->toOutput(['a'], $field));
        $this->assertSame([], $value->toOutput(null, $field));
    }

    public function test_relation_to_stored_and_output(): void
    {
        $value = self::value('relation');
        $single = self::field('relation', ['relation' => ['to' => 'author']]);
        $multiple = self::field('relation', ['relation' => ['to' => 'author', 'multiple' => true]]);

        $this->assertSame('id', $value->shape($single));
        $this->assertSame('id_list', $value->shape($multiple));
        $this->assertSame('a1', $value->toStored('a1', $single));
        $this->assertSame('7', $value->toStored(7, $single));
        $this->assertNull($value->toStored([], $single));
        $this->assertSame(['x', 'y'], $value->toStored(['x', '', 'y'], $multiple));
        $this->assertSame(['x', 'y'], $value->toStored('["x","y"]', $multiple));
        $this->assertNull($value->toStored([], $multiple), 'an empty list is no value');
        $this->assertSame(['x', 'y'], $value->toOutput('["x","y"]', $multiple), 'JSON text an SQL driver returns');
        $this->assertSame(['x', 'y'], $value->toOutput(['x', 'y'], $multiple));
        $this->assertSame('a1', $value->toOutput('a1', $single));
        $this->assertNull($value->toOutput(null, $multiple));

        $this->assertInstanceOf(ListsReferences::class, $value);
        $this->assertSame(['author' => ['x', 'y']], $value->references('["x","y"]', $multiple));
        $this->assertSame(['author' => ['a1']], $value->references('a1', $single));
        $this->assertSame([], $value->references(null, $single));
        $this->assertSame([], $value->references('a1', self::field('relation')), 'a relation without a target');
    }

    public function test_asset_to_stored_and_output(): void
    {
        $value = self::value('asset');
        $single = self::field('asset');
        $multiple = self::field('asset', ['asset' => ['multiple' => true]]);

        $this->assertSame('id', $value->shape($single));
        $this->assertSame('id_list', $value->shape($multiple));
        $this->assertSame('img-1', $value->toStored('img-1', $single));
        $this->assertNull($value->toStored([], $single), 'as AssetFieldType::toStorage()');
        $this->assertNull($value->toStored([], $multiple));
        $this->assertSame(['i1', 'i2'], $value->toStored(['k' => 'i1', 'i2'], $multiple));
        $this->assertSame(['i1', 'i2'], $value->toOutput('["i1","i2"]', $multiple));
        $this->assertSame('img-1', $value->toOutput('img-1', $single));

        $this->assertInstanceOf(ListsReferences::class, $value);
        $this->assertSame(['asset' => ['i1', 'i2']], $value->references('["i1","i2"]', $multiple));
        $this->assertSame(['asset' => ['img-1']], $value->references('img-1', $single));
        $this->assertSame([], $value->references('', $single));
    }

    public function test_unconvertible_input_passes_through(): void
    {
        $this->assertSame('abc', self::value('number')->toStored('abc', self::field('number')));
        $this->assertSame('abc', self::value('number')->toOutput('abc', self::field('number')));
        $this->assertSame('maybe', self::value('boolean')->toStored('maybe', self::field('boolean')));
        $this->assertSame(['x'], self::value('string')->toStored(['x'], self::field('string')));
        $this->assertSame(5, self::value('tags')->toStored(5, self::field('tags')));
        $this->assertSame(['a' => 1], self::value('select')->toStored(['a' => 1], self::field('select')));
        $this->assertSame('22.10.2026', self::value('date')->toStored('22.10.2026', self::field('date')), 'the stored rule refuses it');
    }

    public function test_date_read_is_the_0921_rule(): void
    {
        $rule = new StoredDate();
        $context = new ValidationContext('d', []);

        foreach (['2026-10-22', '2026-10-22T23:15', '2026-10-22T23:15:12', '2026-10-22T23:15:12+00:00', '2026-10-22T23:15:12.5+00:00', '2026-10-22T23:15+00:00', '2026-10-22T23:15:12Z', gmdate(DATE_ATOM), '', null] as $value) {
            $this->assertTrue($rule->validate($value, $context), var_export($value, true));
            if (is_string($value) && $value !== '') {
                $this->assertNotNull(DateValue::read($value), $value . ' is read');
            }
        }
        foreach (['tomorrow', 'now', '2026-10-22 23:15', '22.10.2026', '2026-02-30', '2026-10-22T24:15', ['2026-10-22'], 20261022,
            '2026-02-30T05:00:00+00:00', '2026-11-27T25:00:00+00:00', '2026-11-27T05:61+01:00', '2026-11-31T05:00+01:00', ' 2026-11-27 '] as $value) {
            $this->assertNotTrue($rule->validate($value, $context), var_export($value, true));
            if (is_string($value)) {
                $this->assertNull(DateValue::read($value), $value . ' is not read either');
            }
        }
        $this->assertSame('2026-10-22T23:15:12+02:00', DateValue::read('2026-10-22T23:15:12')?->format(DATE_ATOM), 'in the app timezone');
        $this->assertSame('2026-10-23T01:15:12+02:00', DateValue::read('2026-10-22T23:15:12+00:00')?->format(DATE_ATOM), 'an offset in the app timezone');
        $this->assertEquals([new StoredDate()], self::kinds()->rulesOf(self::field('date')), 'date\'s stored rule, registered with the values');
        $this->assertEquals([new StoredDate()], self::kinds()->rulesOf(self::field('date', ['time' => true])), 'with or without time');
    }
}
