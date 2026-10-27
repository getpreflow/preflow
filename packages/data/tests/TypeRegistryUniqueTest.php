<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\ModelMetadata;
use Preflow\Data\TypeRegistry;

final class TypeRegistryUniqueTest extends TestCase
{
    public function test_definition_parses_unique_index_and_length(): void
    {
        $dir = sys_get_temp_dir() . '/tru_' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/code.json', json_encode([
            'key' => 'code', 'table' => 'codes', 'storage' => 'default',
            'fields' => [
                'slug' => ['type' => 'string', 'unique' => true],
                'code' => ['type' => 'string', 'unique' => ['purpose']],
                'purpose' => ['type' => 'string'],
                'recipient' => ['type' => 'string', 'index' => true, 'length' => 320],
            ],
        ]));
        try {
            $type = (new TypeRegistry($dir))->get('code');
        } finally {
            unlink($dir . '/code.json');
            rmdir($dir);
        }

        $this->assertTrue($type->fields['slug']->unique);
        $this->assertSame(['purpose'], $type->fields['code']->unique);
        $this->assertFalse($type->fields['purpose']->unique);
        $this->assertTrue($type->fields['recipient']->index);
        $this->assertSame(320, $type->fields['recipient']->length);
        $this->assertNull($type->fields['slug']->length);
        $this->assertSame(['slug' => [], 'code' => ['purpose']], $type->uniqueFields());
        $this->assertSame(['recipient'], $type->indexedFields());
        $this->assertArrayNotHasKey('unique', $type->fields['slug']->config);
    }

    public function test_model_metadata_unique_fields(): void
    {
        $this->assertSame(['slug' => [], 'code' => ['purpose']], ModelMetadata::for(UniqueModel::class)->uniqueFields());
    }
}

#[Entity(table: 'uniq')]
final class UniqueModel extends \Preflow\Data\Model
{
    #[Field(unique: true)]
    public string $slug = '';

    #[Field(unique: ['purpose'], index: true, length: 20)]
    public string $code = '';

    public string $purpose = '';
}
