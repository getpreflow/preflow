<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Describe;

use Preflow\Blocks\Describe\ContentDescription;
use Preflow\Blocks\Layouts\AreaDefinition;
use Preflow\Blocks\Layouts\AreaLayout;
use Preflow\Blocks\Layouts\AreaLayoutSet;
use Preflow\Blocks\Layouts\AreaLayoutSets;
use Preflow\Blocks\Tests\BlocksAppTestCase;
use Preflow\Data\DataManager;

/**
 * The content description through a booted app without Folio: the blocks, page layouts, area
 * layout sets and type layouts as declared data, for an OpenAPI or GraphQL schema builder.
 * tests/Fixtures/describe.json is the expected description of this app.
 */
final class ContentDescriptionTest extends BlocksAppTestCase
{
    private const string FIXTURE = __DIR__ . '/../Fixtures/describe.json';

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

    public function test_description_matches_the_fixture(): void
    {
        $json = json_encode($this->description(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

        $this->assertSame((string) file_get_contents(self::FIXTURE), $json);
    }

    public function test_description_is_json_without_class_names(): void
    {
        $description = $this->description();
        $json = json_encode($description, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $description['version']);
        $this->assertSame($json, json_encode(json_decode($json, false, 512, JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR), 'plain data: it survives a JSON round trip unchanged');
        $this->assertStringNotContainsString('App\\\\', $json);
        $this->assertStringNotContainsString('Preflow\\\\', $json);
        $this->assertStringNotContainsString('.twig', $json, 'no template paths');
        $this->assertStringNotContainsString('"class"', $json);
        $this->assertStringNotContainsString('"template"', $json);
        $this->assertStringContainsString('"config":{}', $json, 'a field without options has an empty object, never a list');
    }

    public function test_description_lists_layouts_sets_and_type_layouts(): void
    {
        $description = $this->description();

        $this->assertSame(['banner', 'quote', 'section', 'section_quote'], array_keys($description['blocks']));
        $this->assertTrue($description['blocks']['banner']['renders'], 'a component block renders itself');
        $this->assertFalse($description['blocks']['quote']['renders'], 'a data-only block is rendered by its parent');
        $title = $description['blocks']['section']['fields']['title'];
        $this->assertSame(['type' => 'string', 'label' => null, 'help' => null, 'translatable' => true, 'required' => true, 'list' => false], array_diff_key($title, ['config' => true]));
        $this->assertSame(['field' => 'kind', '=' => 'link'], $description['blocks']['section']['fields']['button_url']['config']['show_when']);
        $this->assertSame('answers', $description['blocks']['section']['fields']['answer']['config']['options_from']);
        $this->assertTrue($description['blocks']['section']['fields']['answers']['list'], 'tags hold a list (FieldKinds, as Folio registers them)');
        $this->assertFalse($description['blocks']['section']['fields']['quotes']['list'], 'a blocks field is described by its type');
        $this->assertSame(['section_quote'], $description['blocks']['section']['fields']['quotes']['config']['allowed']);

        $this->assertSame(['landing', 'standard', 'home'], array_keys($description['layouts']));
        $this->assertSame('{"label":"Hero","label_params":{},"allowed":["hero"],"except":[],"min":null,"max":1}', json_encode($description['layouts']['landing']['areas']['hero']), 'no label parameters: an empty object');

        $this->assertSame(['columns'], array_keys($description['area_layout_sets']));
        $this->assertSame('full', $description['area_layout_sets']['columns']['fallback']);
        $this->assertSame(['full', '2-1'], array_keys($description['area_layout_sets']['columns']['layouts']));
        $this->assertSame(2, $description['area_layout_sets']['columns']['layouts']['2-1']['areas']['c1']['width']);

        $this->assertSame(['page'], array_keys($description['types']), 'only the types with layouts');
        $layouts = $description['types']['page']['layouts'];
        $this->assertSame(['landing', 'standard'], $layouts['allowed']);
        $this->assertSame('standard', $layouts['default']);
        $this->assertSame(['home' => 'home'], $layouts['pinned']);
        $this->assertSame('path', $layouts['slug_field']);
        $this->assertSame(['_layout', '_areas'], array_keys($layouts['values']));
    }

    public function test_hidden_block_field_is_not_described(): void
    {
        $fields = $this->description()['blocks']['section']['fields'];

        $this->assertArrayNotHasKey('secret', $fields, 'a block field declared hidden is no field to an API');
        $this->assertArrayHasKey('quotes', $fields, 'the others are');
        $this->assertArrayNotHasKey('secret', $this->app()->container()->get(ContentDescription::class)->sections()['blocks']['section']['fields'], 'nor in the one description');
    }

    /** @return array<string, mixed> */
    private function description(): array
    {
        $container = $this->app()->container();
        // What Folio registers for its tags field: a list of values.
        $container->get(DataManager::class)->fieldKinds()->list('tags');
        $container->get(AreaLayoutSets::class)->add('columns', static fn (): AreaLayoutSet => new AreaLayoutSet('columns', [
            new AreaLayout('full', 'One column', ['c1' => new AreaDefinition('c1', 'Column 1', width: 1)]),
            new AreaLayout('2-1', 'Wide and narrow', [
                'c1' => new AreaDefinition('c1', 'Column 1', width: 2),
                'c2' => new AreaDefinition('c2', 'Column 2', except: ['columns'], width: 1),
            ], description: 'Two thirds, one third'),
        ], fallback: 'full'));

        return $container->get(ContentDescription::class)->toArray();
    }
}
