<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Content;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Locales;
use Preflow\Data\Validation\MaxChars;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\RecordValidator;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\Types\StringFieldType;
use Preflow\Data\DynamicRecord;
use Preflow\Validation\Message;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidatorFactory;

final class RecordValidatorLengthTest extends TestCase
{
    public function test_number_like_slugs_pass_and_digit_overflow_fails_as_text(): void
    {
        $dir = sys_get_temp_dir() . '/rvl_' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/page.json', json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json',
            'fields' => ['slug' => ['type' => 'string', 'unique' => true], 'code' => ['type' => 'string', 'index' => true, 'length' => 10]],
        ]));
        try {
            $registry = new TypeRegistry($dir, Locales::single('en'));
            $dm = new DataManager(
                drivers: ['json' => new JsonFileDriver($dir . '/store')],
                defaultDriver: 'json',
                typeRegistry: $registry,
                validatorFactory: new ValidatorFactory(new RuleFactory()),
            );
            $fieldTypes = new FieldTypeRegistry();
            $fieldTypes->register(new StringFieldType());
            $validator = new RecordValidator($fieldTypes, $dm);
            $def = $registry->get('page');
            foreach (['2024', '500'] as $i => $slug) {
                $validator->validate(DynamicRecord::fromArray($def, ['uuid' => 'u' . $i, 'slug' => $slug]));
            }
            $this->addToAssertionCount(1);
            $failed = [];
            foreach ([['slug' => str_repeat('9', 192)], ['slug' => 'x', 'code' => '0000000000000']] as $i => $data) {
                try {
                    $validator->validate(DynamicRecord::fromArray($def, ['uuid' => 'f' . $i, ...$data]));
                } catch (ValidationException $e) {
                    $failed[] = $e->errors();
                }
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
        $this->assertSame((new Message('validation.max.string', ['max' => '191']))->english(), $failed[0]['slug'][0] ?? null);
        $this->assertSame((new Message('validation.max.string', ['max' => '10']))->english(), $failed[1]['code'][0] ?? null);
    }

    public function test_unique_and_indexed_strings_get_a_length_rule(): void
    {
        $dir = sys_get_temp_dir() . '/rvl_' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/page.json', json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json',
            'fields' => [
                'slug' => ['type' => 'string', 'unique' => true, 'validate' => ['required']],
                'code' => ['type' => 'string', 'index' => true, 'length' => 10],
                'title' => ['type' => 'string'],
                'ro' => ['type' => 'string', 'unique' => true, 'readonly' => true],
                'number' => ['type' => 'string', 'unique' => ['series']],
                'series' => ['type' => 'string'],
            ],
        ]));
        try {
            $registry = new TypeRegistry($dir, Locales::single('en'));
            $dm = new DataManager(
                drivers: ['json' => new JsonFileDriver($dir . '/store')],
                defaultDriver: 'json',
                typeRegistry: $registry,
                validatorFactory: new ValidatorFactory(new RuleFactory()),
            );
            $fieldTypes = new FieldTypeRegistry();
            $fieldTypes->register(new StringFieldType());
            $rules = (new RecordValidator($fieldTypes, $dm))->rules($registry->get('page'));
        } finally {
            unlink($dir . '/page.json');
            rmdir($dir);
        }

        $this->assertCount(2, $rules['slug']);
        $this->assertSame('required', $rules['slug'][0]);
        $this->assertEquals(new MaxChars(191), $rules['slug'][1]);
        $this->assertEquals([new MaxChars(10)], $rules['code']);
        $this->assertArrayNotHasKey('title', $rules);
        $this->assertSame([], $rules['ro']);
        $this->assertEquals([new MaxChars(191)], $rules['series'], 'a unique field\'s scope is a bounded column too');
    }
}
