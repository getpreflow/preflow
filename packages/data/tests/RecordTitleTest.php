<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Boot\BootManifest;
use Preflow\Core\Config\ParsedJsonFiles;
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

#[Entity(table: 'untitled_books', type: 'untitled_book')]
final class UntitledBook extends Model
{
    #[Id]
    public string $uuid = '';
    #[Field]
    public string $name = '';
    #[Field]
    public string $code = '';
}

#[Entity(table: 'bad_books', type: 'bad_book', titleField: 'missing')]
final class BadTitleBook extends Model
{
    #[Id]
    public string $uuid = '';
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

    public function test_an_unknown_title_field_in_a_model_file_fails_building_the_registry(): void
    {
        file_put_contents($this->dir . '/thing.json', json_encode([
            'key' => 'thing', 'table' => 'thing', 'fields' => ['name' => ['type' => 'string']], 'titleField' => 'missing',
        ]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Type "thing" has the titleField "missing", which is not one of its fields.');

        new TypeRegistry($this->dir); // at boot, not when the type is first used
    }

    public function test_an_unknown_title_field_on_a_class_fails_building_the_registry(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Type "bad_book" has the titleField "missing", which is not one of its fields.');

        new TypeRegistry($this->dir, null, ['bad_book' => BadTitleBook::class]);
    }

    public function test_an_unknown_title_field_in_an_admin_file_fails_building_the_registry(): void
    {
        file_put_contents($this->dir . '/untitled_book.json', json_encode(['titleField' => 'missing']));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Type "untitled_book" has the titleField "missing", which is not one of its fields.');

        new TypeRegistry($this->dir, null, ['untitled_book' => UntitledBook::class]);
    }

    public function test_an_admin_file_may_set_the_title_field_of_a_class(): void
    {
        file_put_contents($this->dir . '/untitled_book.json', json_encode(['titleField' => 'code']));

        $this->assertSame('code', (new TypeRegistry($this->dir, null, ['untitled_book' => UntitledBook::class]))->get('untitled_book')->titleField);
    }

    public function test_class_and_admin_file_disagreeing_on_the_title_field_fails_building_the_registry(): void
    {
        file_put_contents($this->dir . '/titled_book.json', json_encode(['titleField' => 'code']));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('titled_book');
        $this->expectExceptionMessage('"name"');
        $this->expectExceptionMessage('"code"');

        new TypeRegistry($this->dir, null, ['titled_book' => TitledBook::class]);
    }

    public function test_overlay_refuses_a_second_title_field(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('titleField');

        ModelSchema::overlay(ModelSchema::of(TitledBook::class), ['titleField' => 'code'], '/x/titled_book.json', TitledBook::class);
    }

    public function test_class_and_admin_file_may_name_the_same_title_field(): void
    {
        file_put_contents($this->dir . '/titled_book.json', json_encode(['titleField' => 'name']));

        $this->assertSame('name', (new TypeRegistry($this->dir, null, ['titled_book' => TitledBook::class]))->get('titled_book')->titleField);
    }

    public function test_the_default_rule_reads_only_strings(): void
    {
        // A number held in a string field (as JSON storage may give it) is no title by the default rule, as before.
        $type = $this->type(['name' => ['type' => 'string']]);
        $this->assertSame('t1', RecordTitle::of(DynamicRecord::fromArray($type, ['uuid' => 't1', 'name' => 42])));
    }

    public function test_an_explicit_numeric_title_field_is_cast(): void
    {
        $type = $this->type(['name' => ['type' => 'string'], 'number' => ['type' => 'integer']], 'number');
        $this->assertSame('42', RecordTitle::of(DynamicRecord::fromArray($type, ['uuid' => 't1', 'name' => 'n', 'number' => 42])));
        $this->assertSame('1.5', RecordTitle::of(DynamicRecord::fromArray($type, ['uuid' => 't2', 'name' => 'n', 'number' => 1.5])));
    }

    public function test_a_model_class_sets_it_on_its_entity_attribute(): void
    {
        $this->assertSame('name', ModelSchema::of(TitledBook::class)['titleField']);

        $type = (new TypeRegistry($this->dir, null, ['titled_book' => TitledBook::class]))->get('titled_book');
        $this->assertSame('name', $type->titleField);
        $this->assertSame('Dune', RecordTitle::of(DynamicRecord::fromArray($type, ['uuid' => 'b1', 'code' => 'X1', 'name' => 'Dune'])));
    }

    public function test_with_a_manifest_a_later_boot_decodes_no_model_file_for_the_check(): void
    {
        file_put_contents($this->dir . '/thing.json', json_encode([
            'key' => 'thing', 'table' => 'thing', 'fields' => ['name' => ['type' => 'string']], 'titleField' => 'name',
        ]));
        file_put_contents($this->dir . '/untitled_book.json', json_encode(['titleField' => 'code']));
        $classes = ['untitled_book' => UntitledBook::class];

        $recording = BootManifest::recording();
        new TypeRegistry($this->dir, null, $classes, $recording);
        $recorded = $recording->recorded();
        $this->assertTrue($recorded['catalogs']['data.title_fields']);
        $this->assertContains($this->dir, $recorded['sources']);

        ParsedJsonFiles::reset();
        $loaded = BootManifest::fromData(['catalogs' => $recorded['catalogs']]);
        // The class key's admin file is still read by the one-definition check; the plain model file is not.
        new TypeRegistry($this->dir, null, [], $loaded);
        $this->assertSame(0, ParsedJsonFiles::decodeCount());
        new TypeRegistry($this->dir, null, $classes, $loaded);
        $this->assertSame(1, ParsedJsonFiles::decodeCount(), 'only assertOneDefinitionPerKey reads the class key file');
    }

    public function test_a_bad_title_field_fails_when_the_manifest_is_built(): void
    {
        file_put_contents($this->dir . '/thing.json', json_encode([
            'key' => 'thing', 'table' => 'thing', 'fields' => ['name' => ['type' => 'string']], 'titleField' => 'missing',
        ]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Type "thing" has the titleField "missing", which is not one of its fields.');

        new TypeRegistry($this->dir, null, [], BootManifest::recording());
    }
}
