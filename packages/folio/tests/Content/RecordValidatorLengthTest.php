<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Content;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Locales;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\RecordValidator;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\Types\StringFieldType;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidatorFactory;

final class RecordValidatorLengthTest extends TestCase
{
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

        $this->assertSame(['required', 'max:191'], $rules['slug']);
        $this->assertSame(['max:10'], $rules['code']);
        $this->assertArrayNotHasKey('title', $rules);
        $this->assertSame([], $rules['ro']);
    }
}
