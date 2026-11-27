<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Values;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\FieldKinds;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\TypeRegistry;
use Preflow\Data\Values\Core\DateValue;
use Preflow\Data\Values\Core\TextValue;
use Preflow\Data\Values\FieldValue;
use Preflow\Data\Values\RawValue;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidatorFactory;

/** A field type's value on FieldKinds (0.93): registered per type like rules(), RawValue for none. */
final class FieldKindsValuesTest extends TestCase
{
    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir === '' || !is_dir($this->dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    public function test_unknown_type_is_raw(): void
    {
        $kinds = new FieldKinds();
        $field = new TypeFieldDefinition('f', 'my_widget');
        $value = $kinds->valueOf($field);

        $this->assertInstanceOf(RawValue::class, $value);
        $this->assertFalse($kinds->hasValue('my_widget'));
        $this->assertSame('raw', $value->shape($field));
        $this->assertSame(['raw'], array_keys($value->shapes()));
        foreach (['x', 3, null, ['a' => [1, 2]], '["x"]', true] as $given) {
            $this->assertSame($given, $value->toStored($given, $field));
            $this->assertSame($given, $value->toOutput($given, $field));
        }
    }

    public function test_registration_replaces(): void
    {
        $kinds = new FieldKinds();
        $first = new TextValue();
        $kinds->value('slug', $first);
        $this->assertTrue($kinds->hasValue('slug'));
        $this->assertSame($first, $kinds->valueOf(new TypeFieldDefinition('s', 'slug')));

        $second = new class implements FieldValue {
            public function toStored(mixed $input, TypeFieldDefinition $field): mixed
            {
                return is_string($input) ? strtolower($input) : $input;
            }

            public function toOutput(mixed $stored, TypeFieldDefinition $field): mixed
            {
                return $stored;
            }

            public function shape(TypeFieldDefinition $field): string
            {
                return 'string';
            }
        };
        $kinds->value('slug', $second);

        $this->assertSame($second, $kinds->valueOf(new TypeFieldDefinition('s', 'slug')));
        $this->assertSame(['slug' => $second], $kinds->values());
        $this->assertSame('abc', $kinds->valueOf(new TypeFieldDefinition('s', 'slug'))->toStored('ABC', new TypeFieldDefinition('s', 'slug')));
    }

    public function test_core_values_registered_without_any_package(): void
    {
        $kinds = (new DataManager([]))->fieldKinds();

        foreach (['string', 'text', 'number', 'boolean', 'date', 'select', 'tags', 'relation', 'asset'] as $type) {
            $this->assertTrue($kinds->hasValue($type), $type);
        }
        $this->assertInstanceOf(DateValue::class, $kinds->valueOf(new TypeFieldDefinition('d', 'date')));
        $this->assertSame(3.5, $kinds->valueOf(new TypeFieldDefinition('n', 'number'))->toOutput('3.50', new TypeFieldDefinition('n', 'number')));
        $this->assertTrue($kinds->hasRules('date'), 'date\'s stored rule comes with the core values');
    }

    public function test_date_stored_rule_registered_without_folio(): void
    {
        $this->dir = sys_get_temp_dir() . '/fkv_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0777, true);
        file_put_contents($this->dir . '/models/event.json', json_encode([
            'key' => 'event', 'table' => 'event', 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => ['day' => ['type' => 'date']],
        ]));
        $registry = new TypeRegistry($this->dir . '/models');
        $dm = new DataManager(
            drivers: ['json' => new JsonFileDriver($this->dir . '/store')],
            defaultDriver: 'json',
            typeRegistry: $registry,
            validatorFactory: new ValidatorFactory(new RuleFactory()),
        );
        $def = $registry->get('event');

        $dm->saveType(DynamicRecord::fromArray($def, ['uuid' => 'e1', 'day' => '2026-11-27T05:00:00+00:00']));
        $this->assertSame('2026-11-27T05:00:00+00:00', $dm->findType('event', 'e1')?->get('day'), 'a stored shape the type reads');

        foreach (['31.02.2026', '2026-02-30T05:00:00+00:00'] as $value) {
            try {
                $dm->saveType(DynamicRecord::fromArray($def, ['uuid' => 'e2', 'day' => $value]));
                $this->fail($value . ' was saved');
            } catch (ValidationException $e) {
                $this->assertSame(['day' => ['Format is invalid.']], $e->errors(), $value);
            }
        }
        $this->assertNull($dm->findType('event', 'e2'));
    }
}
