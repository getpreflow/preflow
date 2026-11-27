<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Describe;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Describe\Description;
use Preflow\Data\Describe\DescriptionContributor;
use Preflow\Data\Describe\DescriptionContributors;
use Preflow\Data\FieldKinds;
use Preflow\Data\Html\SymfonyHtmlCleaner;
use Preflow\Data\Locales;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeRegistry;
use Preflow\Data\Values\CoreValues;
use Preflow\Data\Values\DescribesShapes;

/**
 * The description of every type and field without any other package: what a REST or GraphQL
 * schema builder reads. tests/Fixtures/describe-types.json is the expected description of the
 * types job and company.
 */
final class DescriptionTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/../Fixtures/describe-types.json';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/describe_types_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        $this->model('job', [
            'label' => 'Job',
            'titleField' => 'title',
            'fields' => [
                'title' => ['type' => 'string', 'label' => 'Title', 'translatable' => true, 'validate' => ['required', 'max:120']],
                'company' => ['type' => 'relation', 'label' => 'Company', 'relation' => ['to' => 'company']],
                'tags' => ['type' => 'tags', 'label' => 'Tags'],
                'body' => ['type' => 'richtext', 'label' => ['en' => 'Description', 'de' => 'Beschreibung'], 'help' => 'site.fields.body_help', 'translatable' => true],
                'kind' => ['type' => 'select', 'label' => 'Kind', 'options' => ['full' => 'Full time', 'part' => 'Part time']],
                // A site's own field type that registered no value: read and written unchanged.
                'swatch' => ['type' => 'swatch', 'label' => 'Colour'],
                'code' => ['type' => 'string', 'unique' => true],
            ],
        ]);
        $this->model('company', [
            'label' => 'site.types.company',
            'creatable' => false,
            'fields' => [
                'name' => ['type' => 'string', 'validate' => ['required']],
                'slug' => ['type' => 'string', 'unique' => ['name']],
                'partners' => ['type' => 'relation', 'relation' => ['to' => 'company', 'multiple' => true]],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    public function test_description_matches_the_fixture(): void
    {
        $json = json_encode($this->description()->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

        $this->assertSame((string) file_get_contents(self::FIXTURE), $json);
    }

    public function test_is_json_without_class_names_or_template_paths(): void
    {
        $description = $this->description()->toArray();
        $json = json_encode($description, JSON_THROW_ON_ERROR);

        $this->assertSame(Description::VERSION, $description['version']);
        $this->assertSame(1, Description::VERSION);
        $this->assertSame($json, json_encode(json_decode($json, false, 512, JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR), 'plain data: it survives a JSON round trip unchanged');
        $this->assertStringNotContainsString('Preflow\\\\', $json);
        $this->assertStringNotContainsString('App\\\\', $json);
        $this->assertStringNotContainsString('.twig', $json);
        $this->assertStringNotContainsString('"class"', $json);
        $this->assertStringNotContainsString('"template"', $json);
        $this->assertStringContainsString('"config":{}', $json, 'a field without options has an empty object, never a list');
    }

    public function test_empty_maps_are_objects(): void
    {
        $empty = sys_get_temp_dir() . '/describe_none_' . bin2hex(random_bytes(4));
        $description = new Description(new TypeRegistry($empty), new FieldKinds(), new DescriptionContributors());

        $this->assertSame('{"version":1,"types":{},"values":{}}', json_encode($description->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_labels_stay_as_declared(): void
    {
        $types = $this->description()->toArray()['types'];

        $this->assertSame('site.types.company', $types['company']['label'], 'a key-shaped label stays the key');
        $this->assertSame('Job', $types['job']['label']);
        $this->assertSame(['en' => 'Description', 'de' => 'Beschreibung'], $types['job']['fields']['body']['label'], 'a label per language stays the map');
        $this->assertSame('site.fields.body_help', $types['job']['fields']['body']['help']);
        $this->assertNull($types['company']['fields']['name']['label'], 'no label declared: null');
    }

    public function test_without_blocks_has_no_blocks_section(): void
    {
        $description = $this->description()->toArray();

        $this->assertSame(['version', 'types', 'values'], array_keys($description));
        $this->assertSame(['label', 'id', 'actions', 'locales', 'title_field', 'fields'], array_keys($description['types']['job']));
    }

    public function test_shapes_listed_once(): void
    {
        $contributors = new DescriptionContributors();
        $contributors->add(static fn (): DescriptionContributor => new ShapedContributor());
        $description = $this->description($contributors)->toArray();

        $this->assertSame(['id', 'id_list', 'raw', 'rich_text', 'shelf', 'string', 'string_list'], array_keys($description['values']), 'every shape a field or a contributor uses, sorted, once');
        $this->assertSame(CoreValues::SHAPES['string'], $description['values']['string'], 'described by the value that names it');
        $this->assertSame('A shelf of things.', $description['values']['shelf']);
        foreach ($description['types'] as $type) {
            foreach ($type['fields'] as $field) {
                $this->assertArrayHasKey($field['value'], $description['values']);
            }
        }
    }

    public function test_contributors_add_sections_and_type_keys(): void
    {
        $asked = 0;
        $contributors = new DescriptionContributors();
        $contributors->add(static function () use (&$asked): DescriptionContributor {
            $asked++;

            return new ShapedContributor();
        });
        $this->assertSame(0, $asked, 'built on first use');

        $description = $this->description($contributors)->toArray();
        $this->description($contributors)->toArray();

        $this->assertSame(1, $asked, 'built once');
        $this->assertSame(['version', 'types', 'values', 'shelves'], array_keys($description));
        $this->assertSame('{}', json_encode($description['shelves']));
        $this->assertSame(['shelf' => 'job-shelf'], array_diff_key($description['types']['job'], array_flip(['label', 'id', 'actions', 'locales', 'title_field', 'fields'])));
        $this->assertArrayNotHasKey('shelf', $description['types']['company'], 'forType() gave nothing for it');
    }

    public function test_a_section_named_like_a_core_key_is_refused(): void
    {
        $contributors = new DescriptionContributors();
        $contributors->add(static fn (): DescriptionContributor => new SectionContributor(['values' => []]));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"values"');
        $this->description($contributors)->toArray();
    }

    public function test_a_type_key_named_like_a_core_key_is_refused(): void
    {
        $contributors = new DescriptionContributors();
        $contributors->add(static fn (): DescriptionContributor => new SectionContributor([], ['fields' => []]));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"fields"');
        $this->description($contributors)->toArray();
    }

    public function test_two_contributors_with_one_section_are_refused(): void
    {
        $contributors = new DescriptionContributors();
        $contributors->add(static fn (): DescriptionContributor => new SectionContributor(['shelves' => []]));
        $contributors->add(static fn (): DescriptionContributor => new SectionContributor(['shelves' => []]));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"shelves"');
        $this->description($contributors)->toArray();
    }

    public function test_translatable_only_with_more_than_one_language(): void
    {
        $single = new Description(new TypeRegistry($this->dir, Locales::single('en')), $this->kinds(), new DescriptionContributors());
        $job = $single->toArray()['types']['job'];

        $this->assertFalse($job['fields']['title']['translatable'], 'one language: values stay plain, so not translatable');
        $this->assertSame(['en'], $job['locales']);
    }

    public function test_a_read_only_type_allows_no_action(): void
    {
        $this->model('log', ['readonly' => true, 'fields' => ['message' => ['type' => 'text']]]);
        $log = $this->description()->toArray()['types']['log'];

        $this->assertSame([], $log['actions']);
        $this->assertNull($log['title_field'], 'no string field to name its records');
    }

    private function description(?DescriptionContributors $contributors = null): Description
    {
        return new Description(new TypeRegistry($this->dir, new Locales(['en', 'de'], 'en')), $this->kinds(), $contributors ?? new DescriptionContributors());
    }

    private function kinds(): FieldKinds
    {
        $kinds = new FieldKinds();
        CoreValues::register($kinds, static fn (): SymfonyHtmlCleaner => new SymfonyHtmlCleaner());
        // What Folio registers for its tags field: a list of values.
        $kinds->list('tags');

        return $kinds;
    }

    /** @param array<string, mixed> $schema */
    private function model(string $key, array $schema): void
    {
        file_put_contents($this->dir . "/{$key}.json", json_encode(['key' => $key, 'table' => $key . 's', 'storage' => 'default'] + $schema, JSON_THROW_ON_ERROR));
    }
}

/** A package that adds a section, a key to the job type and a shape of its own. */
final class ShapedContributor implements DescriptionContributor, DescribesShapes
{
    public function sections(): array
    {
        return ['shelves' => new \stdClass()];
    }

    public function forType(TypeDefinition $def): array
    {
        return $def->key === 'job' ? ['shelf' => 'job-shelf'] : [];
    }

    public function shapes(): array
    {
        return ['shelf' => 'A shelf of things.', 'string' => 'Not the core text: described once, by the field that names it.'];
    }
}

final readonly class SectionContributor implements DescriptionContributor
{
    /**
     * @param array<string, mixed> $sections
     * @param array<string, mixed> $typeKeys
     */
    public function __construct(private array $sections, private array $typeKeys = []) {}

    public function sections(): array
    {
        return $this->sections;
    }

    public function forType(TypeDefinition $def): array
    {
        return $this->typeKeys;
    }
}
