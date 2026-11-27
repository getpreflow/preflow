<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Describe;

use Preflow\Blocks\Describe\ContentDescription;
use Preflow\Blocks\Tests\BlocksAppTestCase;
use Preflow\Data\Describe\Description;
use Preflow\Data\Describe\DescriptionContributor;
use Preflow\Data\TypeRegistry;

/**
 * The blocks package in the one description (Preflow\Data\Describe\Description): its blocks,
 * page layouts and area layout sets as sections, each type's layouts on the type, through a
 * booted app without Folio. ContentDescription's own 0.92 output stays as it was.
 */
final class ContentContributorTest extends BlocksAppTestCase
{
    /** ContentDescription::toArray() of this app in 0.92.0 (unchanged since). */
    private const string FIXTURE_092 = __DIR__ . '/../Fixtures/describe.json';

    protected function setUp(): void
    {
        parent::setUp();
        $this->write('config/data.php', "<?php\nreturn ['drivers' => ['json' => ['driver' => \\Preflow\\Data\\Driver\\JsonFileDriver::class, 'path' => __DIR__ . '/../storage/data']], 'default' => 'json', 'models_path' => __DIR__ . '/models'];\n");
        $this->write('config/blocks.php', "<?php\nreturn [\n"
            . "    'classes' => [\\Preflow\\Blocks\\Tests\\Fixtures\\Checks\\Section::class, \\Preflow\\Blocks\\Tests\\Fixtures\\Checks\\SectionQuote::class],\n"
            . "    'layouts' => [\\Preflow\\Blocks\\Tests\\Fixtures\\Layouts\\Standard::class, \\Preflow\\Blocks\\Tests\\Fixtures\\Layouts\\Home::class],\n"
            . "];\n");
        $this->write('config/models/page.json', (string) json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => ['title' => ['type' => 'string'], 'path' => ['type' => 'string']],
            'layouts' => ['allowed' => ['landing', 'standard'], 'default' => 'standard', 'pinned' => ['home' => 'home'], 'slug_field' => 'path'],
        ]));
        $this->write('config/models/note.json', (string) json_encode([
            'key' => 'note', 'table' => 'note', 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => ['title' => ['type' => 'string']],
        ]));
    }

    public function test_blocks_sections_and_type_layouts(): void
    {
        $container = $this->app()->container();
        $content = $container->get(ContentDescription::class);
        $old = $content->toArray();
        $description = $container->get(Description::class)->toArray();

        $this->assertInstanceOf(DescriptionContributor::class, $content);
        $this->assertSame(['blocks', 'layouts', 'area_layout_sets'], array_keys($content->sections()));
        $this->assertSame(['version', 'types', 'values', 'blocks', 'layouts', 'area_layout_sets'], array_keys($description));
        $this->assertEquals($old['blocks'], self::withoutValues($description['blocks']), 'the same blocks; the one description adds each field\'s "value"');
        $this->assertEquals($old['layouts'], $description['layouts']);
        $this->assertEquals($old['area_layout_sets'], $description['area_layout_sets']);

        $types = $container->get(TypeRegistry::class);
        // The one description adds "output" (0.93): the keys the read output gives and their shapes; toArray() keeps 0.92's.
        $output = ['output' => ['_layout' => 'resolved_layout', '_areas' => 'area_map', '_unplaced' => 'area_map']];
        $this->assertArrayNotHasKey('output', $old['types']['page']['layouts']);
        $this->assertSame(['layouts' => $old['types']['page']['layouts'] + $output], $content->forType($types->get('page')));
        $this->assertSame([], $content->forType($types->get('note')), 'a type without layouts gets nothing');
        $this->assertSame($old['types']['page']['layouts'] + $output, $description['types']['page']['layouts']);
        $this->assertArrayNotHasKey('layouts', $description['types']['note']);
        $this->assertSame(['title', 'path'], array_keys($description['types']['page']['fields']), 'the type itself, described by data');

        $this->assertArrayHasKey('layout_key', $description['values'], 'the shapes its type layouts name');
        $this->assertArrayHasKey('area_map', $description['values']);
        $this->assertArrayHasKey('resolved_layout', $description['values'], 'the read shape of "_layout"');
        $this->assertArrayNotHasKey('areas_value', $description['values'], 'no areas field anywhere');
    }

    public function test_block_fields_name_their_value_shape(): void
    {
        // Final review finding 3: the read output converts every block field through its value,
        // so the one description names each one's shape, and lists the shape in "values".
        $container = $this->app()->container();
        $kinds = $container->get(\Preflow\Data\DataManager::class)->fieldKinds();
        $description = $container->get(Description::class)->toArray();
        $section = $container->get(\Preflow\Blocks\BlockRegistry::class)->get('section');
        $this->assertNotNull($section);

        $fields = $description['blocks']['section']['fields'];
        foreach ($section->fields as $name => $field) {
            $shape = $kinds->valueOf($field)->shape($field);
            $this->assertSame($shape, $fields[$name]['value'], $name);
            $this->assertArrayHasKey($shape, $description['values'], "{$name}: its shape is in \"values\"");
        }
        $this->assertSame('string_list', $fields['answers']['value']);
        $this->assertSame('blocks', $fields['quotes']['value']);
        $this->assertSame('string', $description['blocks']['section_quote']['fields']['text']['value']);
        $this->assertArrayHasKey('blocks', $description['values'], 'a shape only blocks have');
        $this->assertArrayHasKey('string_list', $description['values']);
        $this->assertTrue($fields['title']['translatable'], 'two languages: a translatable block field is a map');
        $this->assertArrayNotHasKey('value', $container->get(ContentDescription::class)->toArray()['blocks']['section']['fields']['title'], 'toArray() keeps its 0.92 keys');
    }

    public function test_block_fields_are_not_translatable_on_a_single_language_site(): void
    {
        // As a type's fields (Task 8): the read output gives one plain value there.
        $this->write('config/i18n.php', "<?php\nreturn ['default' => 'en', 'available' => ['en'], 'fallback' => 'en', 'url_strategy' => 'prefix'];\n");
        $container = $this->app()->container();

        $this->assertFalse($container->get(Description::class)->toArray()['blocks']['section']['fields']['title']['translatable']);
        $this->assertTrue($container->get(ContentDescription::class)->toArray()['blocks']['section']['fields']['title']['translatable'], 'toArray() keeps the declared flag, as in 0.92');
    }

    /**
     * The blocks section without the "value" the one description adds (an areas field had one in 0.92).
     *
     * @param array<string, mixed>|\stdClass $blocks
     * @return array<string, mixed>|\stdClass
     */
    private static function withoutValues(array|\stdClass $blocks): array|\stdClass
    {
        if (!is_array($blocks)) {
            return $blocks;
        }
        foreach ($blocks as $key => $block) {
            foreach (is_array($block['fields']) ? $block['fields'] : [] as $name => $field) {
                if ($field['type'] !== 'areas') {
                    unset($blocks[$key]['fields'][$name]['value']);
                }
            }
        }

        return $blocks;
    }

    public function test_content_description_to_array_is_unchanged(): void
    {
        $container = $this->app()->container();
        $container->get(\Preflow\Blocks\Layouts\AreaLayoutSets::class)->add('columns', static fn (): \Preflow\Blocks\Layouts\AreaLayoutSet => new \Preflow\Blocks\Layouts\AreaLayoutSet('columns', [
            new \Preflow\Blocks\Layouts\AreaLayout('full', 'One column', ['c1' => new \Preflow\Blocks\Layouts\AreaDefinition('c1', 'Column 1', width: 1)]),
            new \Preflow\Blocks\Layouts\AreaLayout('2-1', 'Wide and narrow', [
                'c1' => new \Preflow\Blocks\Layouts\AreaDefinition('c1', 'Column 1', width: 2),
                'c2' => new \Preflow\Blocks\Layouts\AreaDefinition('c2', 'Column 2', except: ['columns'], width: 1),
            ], description: 'Two thirds, one third'),
        ], fallback: 'full'));
        // The one description asked first: building it leaves the 0.92 one as it was.
        $container->get(Description::class)->toArray();

        $json = json_encode($container->get(ContentDescription::class)->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

        $this->assertSame((string) file_get_contents(self::FIXTURE_092), $json);
    }
}
