<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Records;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\Data\Records\InputPart;
use Preflow\Data\Records\RecordInput;
use Preflow\Data\Records\RecordParts;
use Preflow\Data\TypeAction;
use Preflow\Data\TypeActionRefused;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeRegistry;
use Preflow\Validation\Message;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidationRule;
use Preflow\Validation\ValidatorFactory;

/**
 * One write class for any type (0.93): an API body merged onto a record under the rules Folio's
 * form uses, without any other package installed. Partial (an absent field keeps its value), per
 * language (an object replaces only the languages it names), hidden fields keep their stored
 * value; what is no field, no language of the type, read-only or another id is refused together;
 * every value rule is the core's, on save. Nothing is saved by the write class.
 */
final class RecordInputTest extends TestCase
{
    private string $dir;

    private const array FIELDS = [
        'title' => ['type' => 'string', 'translatable' => true, 'validate' => ['required']],
        'body' => ['type' => 'richtext', 'translatable' => true],
        'summary' => ['type' => 'text'],
        'count' => ['type' => 'number'],
        'kind' => ['type' => 'select', 'options' => ['plain' => 'Plain', 'link' => 'Link']],
        'url' => ['type' => 'string', 'show_when' => ['field' => 'kind', '=' => 'link']],
        'code' => ['type' => 'string', 'readonly' => true],
        'tags' => ['type' => 'tags'],
        'photo' => ['type' => 'asset'],
        'photos' => ['type' => 'asset', 'asset' => ['multiple' => true]],
        'target' => ['type' => 'link'],
    ];

    private const array STORED = [
        'uuid' => 'r1',
        'title' => ['en' => 'Hello', 'de' => 'Hallo'],
        'body' => ['en' => '<p>Hi</p>', 'de' => '<p>Hallo</p>'],
        'summary' => 'Plain text',
        'count' => 3,
        'kind' => 'link',
        'url' => 'https://example.org/kept',
        'code' => 'C-1',
        'tags' => ['red', 'green'],
        'photo' => 'i0',
        'photos' => ['i1', 'i2'],
    ];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/record_input_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0777, true);
        $this->model('item', self::FIELDS, ['label' => 'Items']);
        $this->model('inbox', ['message' => ['type' => 'text']], ['creatable' => false]);
        $this->model('fixed', ['name' => ['type' => 'string']], ['readonly' => true]);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    public function test_one_language_keeps_the_others(): void
    {
        $dm = $this->dm();
        $record = $this->stored($dm);

        $written = $this->input($dm)->update($record, ['title' => ['de' => 'Servus']]);
        $dm->saveType($written);

        $this->assertSame(['en' => 'Hello', 'de' => 'Servus'], $this->find($dm)->get('title'));
        $this->assertSame(['en' => '<p>Hi</p>', 'de' => '<p>Hallo</p>'], $this->find($dm)->get('body'), 'a field it does not name keeps every language');
    }

    public function test_partial_update_keeps_unnamed_fields(): void
    {
        $dm = $this->dm();
        $before = $this->stored($dm)->toArray();

        $dm->saveType($this->input($dm)->update($this->find($dm), ['summary' => 'New text']));

        $after = $this->find($dm)->toArray();
        $this->assertSame('New text', $after['summary']);
        unset($before['summary'], $before['_updated_at'], $after['summary'], $after['_updated_at']);
        $this->assertSame($before, $after, 'every field the body does not name keeps its value');
    }

    public function test_hidden_field_keeps_its_stored_value(): void
    {
        $dm = $this->dm();
        $record = $this->stored($dm);

        $written = $this->input($dm)->update($record, ['kind' => 'plain', 'url' => 'https://example.org/new']);

        $this->assertSame('plain', $written->get('kind'));
        $this->assertSame('https://example.org/kept', $written->get('url'), 'the field its show_when hides keeps its stored value');
        $dm->saveType($written);
        $this->assertSame('https://example.org/kept', $this->find($dm)->get('url'));

        $new = $this->input($dm)->create($dm->getTypeRegistry()->get('item'), ['_id' => 'r2', 'title' => ['en' => 'New'], 'kind' => 'plain', 'url' => 'https://example.org/x']);
        $this->assertArrayNotHasKey('url', $new->toArray(), 'a new record keeps nothing for a hidden field');
    }

    public function test_scalar_for_a_translatable_field_sets_the_given_locale(): void
    {
        $dm = $this->dm();
        $input = $this->input($dm);

        $this->assertSame(['en' => 'Hello', 'de' => 'Servus'], $input->update($this->stored($dm), ['title' => 'Servus'], 'de')->get('title'));
        $this->assertSame(['en' => 'Hi there', 'de' => 'Hallo'], $input->update($this->find($dm), ['title' => 'Hi there'])->get('title'), 'without a locale: the type\'s default one');
        $this->assertSame(['en' => '<p>Hi</p>', 'de' => '<p>Neu</p>'], $input->update($this->find($dm), ['body' => '<p>Neu</p>'], 'de')->get('body'));
    }

    public function test_unknown_field_locale_readonly_and_id_are_refused_together(): void
    {
        $dm = $this->dm();
        $record = $this->stored($dm);
        $before = $record->toArray();

        try {
            $this->input($dm)->update($record, ['x' => 1, 'title' => ['fr' => 'Salut'], 'code' => 'C-2', '_id' => 'r2', 'summary' => 'Changed']);
            self::fail('refused');
        } catch (ValidationException $e) {
            $this->assertSame([
                'x' => ['The type "Items" has no field "x".'],
                'title.fr' => ['The type "Items" has no language "fr". Use one of: en, de.'],
                'code' => ['The field "Code" is read-only.'],
                '_id' => ['This record\'s id is "r1": leave out "_id", or give this id.'],
            ], $e->errors());
        }
        $this->assertSame($before, $record->toArray(), 'nothing of the refused body is merged');

        // The same id is no change; on create an id is taken as given.
        $this->assertSame('r1', $this->input($dm)->update($record, ['_id' => 'r1'])->getId());
        $this->assertSame('r9', $this->input($dm)->create($dm->getTypeRegistry()->get('item'), ['_id' => 'r9'])->getId());
        $this->assertNull($this->input($dm)->create($dm->getTypeRegistry()->get('item'), [])->getId(), 'without "_id": none, the save gives one');
    }

    public function test_create_into_a_non_creatable_type_is_refused(): void
    {
        $dm = $this->dm();

        try {
            $this->input($dm)->create($dm->getTypeRegistry()->get('inbox'), ['message' => 'Hi']);
            self::fail('refused');
        } catch (TypeActionRefused $e) {
            $this->assertSame(TypeAction::Create, $e->action);
            $this->assertSame('inbox', $e->type);
        }
        $record = DynamicRecord::fromArray($dm->getTypeRegistry()->get('inbox'), ['uuid' => 'm1', 'message' => 'Hi']);
        $this->assertSame('Hello', $this->input($dm)->update($record, ['message' => 'Hello'])->get('message'), 'its records are still changed');
    }

    public function test_update_of_a_readonly_type_is_refused(): void
    {
        $dm = $this->dm();
        $def = $dm->getTypeRegistry()->get('fixed');

        $this->expectException(TypeActionRefused::class);
        $this->input($dm)->update(DynamicRecord::fromArray($def, ['uuid' => 'f1', 'name' => 'A']), ['name' => 'B']);
    }

    public function test_save_type_then_refuses_like_folio(): void
    {
        $dm = $this->dm();
        $def = $dm->getTypeRegistry()->get('item');

        // Shapes the write class takes: the values are the core rules' to judge, on save.
        $record = $this->input($dm)->create($def, ['_id' => 'r5', 'title' => ['en' => ''], 'kind' => 'other', 'target' => ['kind' => 'url', 'target' => 'javascript:alert(1)']]);
        try {
            $dm->saveType($record);
            self::fail('refused on save');
        } catch (ValidationException $e) {
            $this->assertSame([
                'title' => ['This field is required.'],
                'kind' => ['Must be one of: plain, link.'],
                'target' => ['Must be a web address starting with https:// or http://, or a path on this site starting with /.'],
            ], $e->errors());
        }
        $this->assertNull($dm->findType('item', 'r5'), 'nothing saved');
    }

    public function test_media_references_replace_and_empty_like_the_form(): void
    {
        $dm = $this->dm();
        $input = $this->input($dm);

        $replaced = $input->update($this->stored($dm), ['photos' => ['i3'], 'photo' => 'i4']);
        $this->assertSame(['i3'], $replaced->get('photos'), 'a list replaces the stored one');
        $this->assertSame('i4', $replaced->get('photo'));

        $emptied = $input->update($this->find($dm), ['photos' => []]);
        $this->assertNull($emptied->get('photos'), '[] empties it, as the form stores no selection');
        $this->assertSame('i0', $emptied->get('photo'), 'an absent field keeps its references');
    }

    public function test_neither_method_saves(): void
    {
        $dm = $this->dm();
        $this->stored($dm);
        $input = $this->input($dm);

        $input->update($this->find($dm), ['summary' => 'Not saved']);
        $input->create($dm->getTypeRegistry()->get('item'), ['_id' => 'r7', 'title' => ['en' => 'X']]);

        $this->assertSame('Plain text', $this->find($dm)->get('summary'));
        $this->assertNull($dm->findType('item', 'r7'));
    }

    public function test_part_keys_go_to_their_input_part(): void
    {
        $dm = $this->dm();
        $parts = new RecordParts();
        $parts->input(static fn (): InputPart => new ExtraPart());
        $input = new RecordInput($dm, $parts, self::validators());

        $written = $input->update($this->stored($dm), ['_extra' => 'given', 'summary' => 'S']);

        $this->assertSame('given!', $written->get('_extra'), 'the part merged its key');
        $this->assertSame('S', $written->get('summary'));
        try {
            $input->update($this->find($dm), ['_extra' => 'bad', 'x' => 1]);
            self::fail('refused');
        } catch (ValidationException $e) {
            $this->assertSame(['x', '_extra'], array_keys($e->errors()), 'a part\'s refusal comes with the others, in one exception');
        }
    }

    public function test_a_hidden_fields_value_is_not_merged_and_not_refused(): void
    {
        $this->model('note', [
            'kind' => ['type' => 'select', 'options' => ['plain', 'list']],
            'items' => ['type' => 'picky', 'show_when' => ['field' => 'kind', '=' => 'list']],
        ]);
        $dm = $this->dm();
        $dm->fieldKinds()->value('picky', new PickyValue());
        $def = $dm->getTypeRegistry()->get('note');
        $record = DynamicRecord::fromArray($def, ['uuid' => 'n1', 'kind' => 'list', 'items' => 'kept']);

        // Hidden by the new kind: kept as stored, its value never merged, so never refused (as Folio's form skips it).
        $written = $this->input($dm)->update($record, ['kind' => 'plain', 'items' => 'bad']);
        $this->assertSame('kept', $written->get('items'));

        try {
            $this->input($dm)->update(DynamicRecord::fromArray($def, ['uuid' => 'n1', 'kind' => 'list', 'items' => 'kept']), ['items' => 'bad']);
            self::fail('a shown field\'s value is merged and refused');
        } catch (ValidationException $e) {
            $this->assertSame(['items' => ['Picky.', 'Bad item.']], $e->errors());
        }
    }

    private function input(DataManager $dm): RecordInput
    {
        return new RecordInput($dm, new RecordParts(), self::validators());
    }

    private static function validators(): ValidatorFactory
    {
        return new ValidatorFactory(new RuleFactory());
    }

    private function stored(DataManager $dm): DynamicRecord
    {
        $dm->saveType(DynamicRecord::fromArray($dm->getTypeRegistry()->get('item'), self::STORED), validate: false);

        return $this->find($dm);
    }

    private function find(DataManager $dm): DynamicRecord
    {
        $record = $dm->findType('item', 'r1');
        $this->assertNotNull($record);

        return $record;
    }

    /**
     * @param array<string, array<string, mixed>> $fields
     * @param array<string, mixed>                $options
     */
    private function model(string $key, array $fields, array $options = []): void
    {
        file_put_contents($this->dir . "/models/{$key}.json", (string) json_encode([
            'key' => $key, 'table' => $key, 'storage' => 'json', 'id_field' => 'uuid', 'fields' => $fields,
        ] + $options));
    }

    private function dm(): DataManager
    {
        $dm = new DataManager(['json' => new JsonFileDriver($this->dir . '/data')], 'json', new TypeRegistry($this->dir . '/models', new Locales(['en', 'de'], 'en')), self::validators());
        // A site's own field type with its own check, as preflow/fields registers its link.
        $dm->fieldKinds()->rules('link', static fn (array $config): array => [new SiteLinkRule()]);

        return $dm;
    }
}

/** Refuses any link that is not https, with the link's message. */
final class SiteLinkRule implements ValidationRule
{
    public function validate(mixed $value, ValidationContext $context): true|Message
    {
        $target = is_array($value) ? ($value['target'] ?? null) : null;

        return $value === null || (is_string($target) && str_starts_with($target, 'https://')) ? true : new Message('validation.link.url');
    }
}

/** A package's input part: "_extra" stored with a mark, "bad" refused. */
final class ExtraPart implements InputPart
{
    public function keys(TypeDefinition $def): array
    {
        return ['_extra'];
    }

    public function merge(TypeDefinition $def, array $stored, array $input): array
    {
        if ($input['_extra'] === 'bad') {
            throw new ValidationException(new \Preflow\Validation\ValidationResult(['_extra' => ['Bad.']]));
        }
        $stored['_extra'] = $input['_extra'] . '!';

        return $stored;
    }
}

/** A value that merges partial input (MergesInput) and refuses "bad"; "bad" also fails its own check. */
final class PickyValue implements \Preflow\Data\Values\FieldValue, \Preflow\Data\Values\MergesInput
{
    public function toStored(mixed $input, \Preflow\Data\TypeFieldDefinition $field): mixed
    {
        return $input;
    }

    public function toOutput(mixed $stored, \Preflow\Data\TypeFieldDefinition $field): mixed
    {
        return $stored;
    }

    public function shape(\Preflow\Data\TypeFieldDefinition $field): string
    {
        return 'raw';
    }

    public function merge(mixed $stored, mixed $input, \Preflow\Data\TypeFieldDefinition $field): mixed
    {
        if ($input === 'bad') {
            throw new ValidationException(new \Preflow\Validation\ValidationResult(['items' => ['Picky.', 'Bad item.']]));
        }

        return $input;
    }
}
