<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\Data\Model;
use Preflow\Data\ModelSchema;
use Preflow\Data\RecordTitle;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\TypeRegistry;

#[Entity(table: 'titled_books', type: 'titled_book', titleField: 'name')]
final class TitledBook extends Model
{
    #[Id]
    public string $uuid = '';
    #[Field]
    public string $code = '';
    #[Field]
    public string $name = '';
}

final class RecordTitleTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rt_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    /** @param array<string, array<string, mixed>> $fields */
    private function type(array $fields, ?string $titleField = null, ?Locales $locales = null): TypeDefinition
    {
        $schema = ['key' => 'thing', 'table' => 'thing', 'storage' => 'json', 'id_field' => 'uuid', 'fields' => $fields];
        if ($titleField !== null) {
            $schema['titleField'] = $titleField;
        }
        file_put_contents($this->dir . '/thing.json', json_encode($schema));

        return (new TypeRegistry($this->dir, $locales))->get('thing');
    }

    public function test_default_is_the_first_string_field_that_is_not_hidden(): void
    {
        $type = $this->type([
            'count' => ['type' => 'number'],
            'pin' => ['type' => 'string', 'hidden' => true],
            'name' => ['type' => 'string'],
            'note' => ['type' => 'string'],
        ]);

        $this->assertNull($type->titleField);
        $this->assertSame('Hello', RecordTitle::of(DynamicRecord::fromArray($type, ['uuid' => 't1', 'pin' => 'p', 'name' => 'Hello', 'note' => 'X'])));
        // Only the first such field counts: empty means the id, not the next string field.
        $this->assertSame('t2', RecordTitle::of(DynamicRecord::fromArray($type, ['uuid' => 't2', 'name' => '', 'note' => 'X'])));
    }

    public function test_explicit_title_field(): void
    {
        $type = $this->type(['name' => ['type' => 'string'], 'headline' => ['type' => 'text']], 'headline');

        $this->assertSame('headline', $type->titleField);
        $this->assertSame('Big news', RecordTitle::of(DynamicRecord::fromArray($type, ['uuid' => 't1', 'name' => 'n', 'headline' => 'Big news'])));
    }

    public function test_a_translatable_title_is_read_in_the_locale(): void
    {
        $type = $this->type(['title' => ['type' => 'string', 'translatable' => true]], 'title', new Locales(['en', 'de'], 'en'));
        $record = DynamicRecord::fromArray($type, ['uuid' => 't1', 'title' => ['en' => 'Lisbon', 'de' => 'Lissabon']]);

        $this->assertSame('Lissabon', RecordTitle::of($record, 'de'));
        $this->assertSame('Lisbon', RecordTitle::of($record, 'en'));
        $this->assertSame('Lisbon', RecordTitle::of($record), 'null is the default locale');
    }

    public function test_falls_back_to_the_id(): void
    {
        $this->assertSame('t1', RecordTitle::of(DynamicRecord::fromArray($this->type(['count' => ['type' => 'number']]), ['uuid' => 't1', 'count' => 3])));
        $this->assertSame('t2', RecordTitle::of(DynamicRecord::fromArray($this->type(['name' => ['type' => 'string']], 'name'), ['uuid' => 't2', 'name' => ''])));
        $this->assertSame('t3', RecordTitle::of(DynamicRecord::fromArray($this->type(['name' => ['type' => 'string']], 'name'), ['uuid' => 't3'])));
    }

    public function test_an_unknown_title_field_names_the_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Type "thing" has the titleField "missing", which is not one of its fields.');

        new TypeDefinition(
            key: 'thing',
            table: 'thing',
            storage: 'json',
            fields: ['name' => new TypeFieldDefinition(name: 'name', type: 'string')],
            titleField: 'missing',
        );
    }

    public function test_an_unknown_title_field_in_a_model_file_fails_loading_the_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"thing"');

        $this->type(['name' => ['type' => 'string']], 'missing');
    }

    public function test_a_model_class_sets_it_on_its_entity_attribute(): void
    {
        $this->assertSame('name', ModelSchema::of(TitledBook::class)['titleField']);

        $type = (new TypeRegistry($this->dir, null, ['titled_book' => TitledBook::class]))->get('titled_book');
        $this->assertSame('name', $type->titleField);
        $this->assertSame('Dune', RecordTitle::of(DynamicRecord::fromArray($type, ['uuid' => 'b1', 'code' => 'X1', 'name' => 'Dune'])));
    }
}
