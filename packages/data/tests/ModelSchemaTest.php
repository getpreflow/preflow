<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\ModelMetadata;
use Preflow\Data\ModelSchema;
use Preflow\Data\Tests\Fixtures\ModelSchema\ApiClient;
use Preflow\Data\Tests\Fixtures\ModelSchema\Job;
use Preflow\Data\TypeRegistry;
use Preflow\Data\Tests\Fixtures\ModelSchema\PlainPost;
use Preflow\Data\Tests\Fixtures\ModelSchema\UntypedArray;
use Preflow\Data\Tests\Fixtures\ModelSchema\TranslatableString;
use Preflow\Data\Tests\Fixtures\ModelSchema\UntypedObject;
use Preflow\Data\Tests\Fixtures\ModelSchema\WithPlainProperty;

final class ModelSchemaTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // The monorepo's root autoloader does not map this package's test namespace.
        foreach (glob(__DIR__ . '/Fixtures/ModelSchema/*.php') as $fixture) {
            require_once $fixture;
        }
    }

    public function test_a_class_gives_the_schema_its_json_file_would(): void
    {
        $this->assertSame(
            json_decode(file_get_contents(__DIR__ . '/Fixtures/ModelSchema/job.json'), true),
            ModelSchema::of(Job::class),
        );
    }

    public function test_an_unmarked_model_is_not_a_type(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('#[Entity(type: …)]');
        ModelSchema::of(PlainPost::class);
    }

    public function test_an_array_property_needs_a_field_type(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('blocks, matrix, asset or relation');
        ModelSchema::of(UntypedArray::class);
    }

    public function test_an_object_property_needs_a_field_type_too(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('needs a field type');
        ModelSchema::of(UntypedObject::class);
    }

    public function test_a_translatable_property_must_take_the_locale_map(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Property [' . TranslatableString::class . '::$title] is translatable');
        $this->expectExceptionMessage('type it array|string|null');
        ModelSchema::of(TranslatableString::class);
    }

    public function test_a_property_without_field_or_relation_is_not_a_field(): void
    {
        $this->assertArrayNotHasKey('scratch', ModelSchema::of(WithPlainProperty::class)['fields']);
    }

    public function test_metadata_knows_the_type_and_old_entities_stay_untyped(): void
    {
        $this->assertSame('job', ModelMetadata::for(Job::class)->type);
        $this->assertNull(ModelMetadata::for(PlainPost::class)->type);
    }

    public function test_exact_is_data_in_class_and_file_schemas(): void
    {
        $schema = ModelSchema::of(ApiClient::class);
        $this->assertSame(['type' => 'string', 'exact' => true], $schema['fields']['apiKey']);
        $this->assertSame(['type' => 'string'], $schema['fields']['name']);
        $this->assertContains('exact', ModelSchema::SCHEMA_FIELD_KEYS, 'an admin file may not set it on a class-backed type');

        $dir = sys_get_temp_dir() . '/preflow_exact_' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            file_put_contents($dir . '/api_key.json', json_encode([
                'key' => 'api_key',
                'table' => 'api_key',
                'fields' => ['name' => ['type' => 'string'], 'secret' => ['type' => 'string', 'exact' => true]],
            ]));
            $def = (new TypeRegistry($dir))->get('api_key');
        } finally {
            array_map('unlink', glob($dir . '/*'));
            rmdir($dir);
        }

        $this->assertTrue($def->fields['secret']->exact);
        $this->assertFalse($def->fields['name']->exact);
        $this->assertArrayNotHasKey('exact', $def->fields['secret']->config);
    }
}
