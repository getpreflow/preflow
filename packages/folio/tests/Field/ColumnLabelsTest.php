<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Field;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\Testing\ChecksFrameBalance;

/**
 * The columns block of preflow/block-library in Folio's editor: each column is labelled with its
 * number in the interface language ("block-library.columns.column" with the area's labelParams),
 * in the layout and in the panel "Not shown in this layout" alike. Since 0.92.1 also: a switch of its
 * layout through the address the picker posts to, its name in the preview and its icon in the picker.
 */
final class ColumnLabelsTest extends TestCase
{
    use ChecksFrameBalance;
    use PublishingScaffold;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\BlockLibrary\\BlockLibraryServiceProvider::class];\n");
        $this->write('config/blocks.php', "<?php\nreturn ['classes' => [\\Preflow\\BlockLibrary\\Columns\\Columns::class, \\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\ProseBlock\\ProseBlock::class]];\n");
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    /** @return array<string, array{string, list<string>, string}> */
    public static function languages(): array
    {
        return [
            'English' => ['en', ['Column 1', 'Column 2', 'Column 3'], 'Column 4'],
            'German' => ['de', ['Spalte 1', 'Spalte 2', 'Spalte 3'], 'Spalte 4'],
            'French' => ['fr', ['Colonne 1', 'Colonne 2', 'Colonne 3'], 'Colonne 4'],
        ];
    }

    /** @param list<string> $placed */
    #[RunInSeparateProcess]
    #[DataProvider('languages')]
    public function test_each_column_is_labelled_with_its_number(string $locale, array $placed, string $hidden): void
    {
        $this->write('config/folio.php', "<?php\nreturn ['path' => '/folio', 'locale' => " . var_export($locale, true) . "];\n");
        $prose = static fn (string $uid): array => ['_uid' => $uid, '_block' => 'prose', 'body' => ['en' => ''], 'caption' => ['en' => 'Text ' . $uid], 'quotes' => []];
        $this->working('post', 'p1', [
            'title' => ['en' => 'Hello'],
            'slug' => ['en' => 'hello'],
            'sections' => [['_uid' => '9a9a9a9a9a9a9a9a', '_block' => 'columns', 'columns' => ['layout' => '1-1-1', 'areas' => [
                'c1' => [$prose('a1a1a1a1a1a1a1a1')],
                'c2' => [$prose('b2b2b2b2b2b2b2b2')],
                'c3' => [$prose('c3c3c3c3c3c3c3c3')],
                // Kept from a four-column layout: shown in the panel, labelled as that layout names it.
                'c4' => [$prose('d4d4d4d4d4d4d4d4')],
            ]]]],
        ]);

        $response = $this->get('/folio/post/p1/edit');
        $form = (string) $response->getBody();
        $this->assertSame(200, $response->getStatusCode(), substr($form, 0, 3000));

        foreach ($placed as $label) {
            $this->assertStringContainsString('>' . $label . ' <span', $form);
        }
        $panel = substr($form, (int) strpos($form, 'data-areas-panel'));
        $this->assertStringContainsString('>' . $hidden . ' <span', $panel, 'the hidden column, by the label the four-column layout gives it');
        $this->assertStringNotContainsString(':n <span', $form, 'no placeholder left');
    }

    private const string GRID = '9a9a9a9a9a9a9a9a';

    /** A post with a columns block of three columns. */
    private function columnsPost(): void
    {
        $prose = static fn (string $uid): array => ['_uid' => $uid, '_block' => 'prose', 'body' => ['en' => ''], 'caption' => ['en' => 'Text ' . $uid], 'quotes' => []];
        $this->working('post', 'p1', [
            'title' => ['en' => 'Hello'],
            'slug' => ['en' => 'hello'],
            'sections' => [['_uid' => self::GRID, '_block' => 'columns', 'columns' => ['layout' => '1-1-1', 'areas' => [
                'c1' => [$prose('a1a1a1a1a1a1a1a1')],
                'c2' => [$prose('b2b2b2b2b2b2b2b2')],
                'c3' => [$prose('c3c3c3c3c3c3c3c3')],
            ]]]],
        ]);
    }

    #[RunInSeparateProcess]
    public function test_switching_the_columns_layout_answers_the_swapped_section(): void
    {
        $this->columnsPost();
        $form = (string) $this->get('/folio/post/p1/edit')->getBody();
        $this->assertSame(1, preg_match('~<select [^>]*name="sections\[' . self::GRID . '\]\[columns\]\[layout\]"[^>]*hx-post="([^"]+)"~', $form, $m), 'the columns picker posts to the switch');
        $url = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);

        $response = $this->post($url, ['sections' => [self::GRID => ['columns' => ['layout' => '1-1']]]]);

        $html = (string) $response->getBody();
        $this->assertSame(200, $response->getStatusCode(), substr($html, 0, 2000));
        $this->assertStringContainsString('data-areas-section', $html);
        $this->assertMatchesRegularExpression('~<option value="1-1" selected>~', $html, 'the chosen layout');
        $this->assertStringContainsString('name="sections[' . self::GRID . '][columns][areas][c2][_order][]"', $html);
        $panel = substr($html, (int) strpos($html, 'data-areas-panel'));
        $this->assertStringContainsString('value="c3c3c3c3c3c3c3c3"', $panel, 'the third column waits in the panel');
    }

    #[RunInSeparateProcess]
    public function test_the_preview_names_the_columns_block_in_words(): void
    {
        $app = $this->app();
        $marks = $app->container()->get(\Preflow\Blocks\BlockPreviewMarks::class);
        $columns = $app->container()->get(\Preflow\Blocks\BlockRegistry::class)->get('columns');
        $this->assertNotNull($columns);

        // The preview renders as a visitor sees the page (PreviewController), where Folio's own texts know only Folio's groups.
        $preview = \Preflow\Testing\ContextFactory::forTest()->admin('en')->asVisitor()->public()->in('en');
        $attributes = \Preflow\Core\Context\Contexts::run($preview, static fn (): array => $marks->attributes($columns, ['_uid' => self::GRID]));

        $this->assertSame(['data-folio-block' => self::GRID, 'data-folio-label' => 'Columns'], $attributes);
    }

    #[RunInSeparateProcess]
    public function test_the_columns_block_has_its_icon_in_the_picker(): void
    {
        $attribute = (new \ReflectionClass(\Preflow\BlockLibrary\Columns\Columns::class))->getAttributes(\Preflow\Blocks\Block::class)[0]->newInstance();
        $sprite = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/icons.svg');
        $this->assertStringContainsString('<symbol id="' . $attribute->icon . '"', $sprite, 'the block\'s icon is drawn by Folio\'s sprite');

        $this->columnsPost();
        $form = (string) $this->get('/folio/post/p1/edit')->getBody();
        $this->assertMatchesRegularExpression('~icons\.svg[^"#]*#columns"~', $form, 'its tile in the block picker');
    }
}
