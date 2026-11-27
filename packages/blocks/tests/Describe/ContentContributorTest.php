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
        $this->assertEquals($old['blocks'], $description['blocks']);
        $this->assertEquals($old['layouts'], $description['layouts']);
        $this->assertEquals($old['area_layout_sets'], $description['area_layout_sets']);

        $types = $container->get(TypeRegistry::class);
        $this->assertSame(['layouts' => $old['types']['page']['layouts']], $content->forType($types->get('page')));
        $this->assertSame([], $content->forType($types->get('note')), 'a type without layouts gets nothing');
        $this->assertSame($old['types']['page']['layouts'], $description['types']['page']['layouts']);
        $this->assertArrayNotHasKey('layouts', $description['types']['note']);
        $this->assertSame(['title', 'path'], array_keys($description['types']['page']['fields']), 'the type itself, described by data');

        $this->assertArrayHasKey('layout_key', $description['values'], 'the shapes its type layouts name');
        $this->assertArrayHasKey('area_map', $description['values']);
        $this->assertArrayNotHasKey('areas_value', $description['values'], 'no areas field anywhere');
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
