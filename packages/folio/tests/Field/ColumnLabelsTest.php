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
 * in the layout and in the panel "Not shown in this layout" alike.
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
}
