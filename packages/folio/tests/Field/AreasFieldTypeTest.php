<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Field;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Blocks\Layouts\AreasValue;
use Preflow\Folio\Content\TranslationStatus;
use Preflow\Folio\Media\FolioRecordReferences;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\Testing\ChecksFrameBalance;
use Preflow\I18n\Machine\Scope;
use Preflow\I18n\Machine\TextSources;
use Psr\Http\Message\ResponseInterface;

/**
 * The areas field in Folio (0.92): a grid block (Fixtures\Blocks\GridBlock, an areas field over the
 * set "grid" the test registers with GridSetProvider) in the "sections" blocks field of a post. Its
 * columns are drawn by the page layout editor, a switch to fewer columns keeps the column in the
 * panel "Not shown in this layout", and blocks inside a column get everything blocks inside a
 * blocks field get: depth, asset usage, translation, sanitized rich text, fresh uids.
 */
final class AreasFieldTypeTest extends TestCase
{
    use ChecksFrameBalance;
    use PublishingScaffold;

    private const string GRID = '9a9a9a9a9a9a9a9a';
    private const string LEFT = 'a1a1a1a1a1a1a1a1';
    private const string RIGHT = 'b2b2b2b2b2b2b2b2';
    private const string PHOTO = 'photo-only-in-a-column';
    private const string EVIL = '<p onclick="steal()">kept</p><script>steal()</script>';

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\GridSetProvider::class];\n");
        $this->blocksConfig();
        $this->write('resources/folio/frontend/post.twig', '<html><body><main>{{ blocks(record.sections|default([])) }}</main></body></html>');
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    private function blocksConfig(string $more = ''): void
    {
        $this->write('config/blocks.php', "<?php\nreturn ['classes' => ["
            . "\\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\GridBlock\\GridBlock::class, \\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\ProseBlock\\ProseBlock::class, "
            . "\\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\QuoteBlock::class, \\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\GalleryBlock::class]{$more}];\n");
    }

    #[RunInSeparateProcess]
    public function test_form_draws_the_columns_and_a_save_keeps_columns_not_posted(): void
    {
        $this->post_('p1', '1-1', ['c1' => [self::prose(self::LEFT, 'Left')], 'c2' => [self::prose(self::RIGHT, 'Right')]]);

        $form = $this->html($this->get('/folio/post/p1/edit'));

        $this->assertStringContainsString('name="sections[' . self::GRID . '][columns][layout]"', $form, 'the picker of the grid\'s layouts');
        $this->assertMatchesRegularExpression('/<option value="1-1" selected>Two columns<\/option>/', $form);
        $this->assertStringContainsString('name="sections[' . self::GRID . '][columns][areas][c1][_order][]" value="' . self::LEFT . '"', $form);
        $this->assertStringContainsString('name="sections[' . self::GRID . '][columns][areas][c2][_order][]" value="' . self::RIGHT . '"', $form);
        $this->assertStringContainsString('set=grid', $form, 'a switch names the set');
        $this->assertStringContainsString('>Column 2 <span', $form, 'each column labelled as its layout names it');

        $body = self::posted($form);
        $body['sections'][self::GRID]['columns']['areas']['c1'][self::LEFT]['caption'] = 'Left, edited';
        unset($body['sections'][self::GRID]['columns']['areas']['c2']);
        $saved = $this->post('/folio/post/p1', $body);
        $this->assertSame(302, $saved->getStatusCode(), substr((string) $saved->getBody(), 0, 3000));

        $columns = AreasValue::fromStored($this->stored('post', 'p1')->get('sections')[0]['columns']);
        $this->assertSame('1-1', $columns->layout);
        $this->assertSame('Left, edited', $columns->areas['c1'][0]['caption']['en']);
        $this->assertSame([self::prose(self::RIGHT, 'Right')], $columns->areas['c2'], 'a column the form did not post keeps its blocks');
    }

    #[RunInSeparateProcess]
    public function test_switch_to_fewer_columns_keeps_the_dropped_column_in_the_panel(): void
    {
        $this->post_('p1', '1-1', ['c1' => [self::prose(self::LEFT, 'Left')], 'c2' => [self::prose(self::RIGHT, 'Right')]]);
        $form = $this->html($this->get('/folio/post/p1/edit'));
        $name = 'sections[' . self::GRID . '][columns]';

        $answer = $this->areas($form, [], 'full');

        $this->assertStringContainsString('data-areas-section', $answer);
        $this->assertMatchesRegularExpression('/<option value="full" selected>Full width<\/option>/', $answer);
        $panel = $this->between($answer, 'data-areas-panel', '</section>');
        $this->assertStringContainsString('Not shown in this layout', $panel);
        $this->assertStringContainsString('name="' . $name . '[areas][c2][_order][]" value="' . self::RIGHT . '"', $panel, 'the dropped column, as a card');
        $this->assertStringContainsString('value="Right"', $panel);
        $this->assertStringContainsString('name="_areas_move" value="' . self::RIGHT . ':c1"', $panel, 'movable to a column of the new layout');
        $this->assertStringNotContainsString('name="' . $name . '[areas][c2][_order][]" value="' . self::RIGHT . '"', $this->before($answer, 'data-areas-panel'));
        $this->assertSame('1-1', $this->stored('post', 'p1')->get('sections')[0]['columns']['layout'], 'nothing stored');

        // Saved as switched: the dropped column is kept; switching back shows it in its place.
        $body = self::posted($form);
        $body['sections'][self::GRID]['columns']['layout'] = 'full';
        $this->assertSame(302, $this->post('/folio/post/p1', $body)->getStatusCode());
        $columns = AreasValue::fromStored($this->stored('post', 'p1')->get('sections')[0]['columns']);
        $this->assertSame('full', $columns->layout);
        $this->assertSame([self::RIGHT], array_column($columns->areas['c2'], '_uid'), 'kept, not shown');
        $back = $this->areas($this->html($this->get('/folio/post/p1/edit')), [], '1-1');
        $this->assertStringNotContainsString('data-areas-panel', $back);
        $this->assertStringContainsString('name="' . $name . '[areas][c2][_order][]" value="' . self::RIGHT . '"', $back);

        // A move puts the block at the end of a column of the layout.
        $moved = $this->areas($form, ['_areas_move' => self::RIGHT . ':c1'], 'full');
        $this->assertStringNotContainsString('data-areas-panel', $moved);
        $c1 = $this->after($moved, 'name="' . $name . '[areas][c1][_order][]" value=""');
        $this->assertLessThan(strpos($c1, 'value="' . self::RIGHT . '"'), strpos($c1, 'value="' . self::LEFT . '"'));
        $this->assertStringContainsString('name="' . $name . '[areas][c2][_order][]" value=""', $moved, 'the emptied column posts, empty');
    }

    #[RunInSeparateProcess]
    public function test_nesting_counts_against_max_depth(): void
    {
        $this->blocksConfig(", 'max_depth' => 2");
        $quote = ['_uid' => 'c3c3c3c3c3c3c3c3', '_block' => 'quote', 'text' => ['en' => 'Kept']];
        $this->post_('p1', 'full', ['c1' => [self::prose(self::LEFT, 'Left', [$quote])]]);

        $form = $this->html($this->get('/folio/post/p1/edit'));

        $column = $this->after($form, 'name="sections[' . self::GRID . '][columns][areas][c1][_order][]" value="' . self::LEFT . '"');
        $this->assertStringContainsString('is-too-deep', $column, 'a column is one level of blocks: its blocks\' own blocks are past max_depth 2');
        $this->assertStringNotContainsString('is-too-deep', $this->before($form, 'name="sections[' . self::GRID . '][columns][areas][c1]'));

        $body = self::posted($form);
        $body['sections'][self::GRID]['columns']['areas']['c1'][self::LEFT]['quotes'] = ['_order' => ['']];
        $this->assertSame(302, $this->post('/folio/post/p1', $body)->getStatusCode());
        $this->assertSame([$quote], $this->stored('post', 'p1')->get('sections')[0]['columns']['areas']['c1'][0]['quotes'], 'past max_depth the stored blocks stay');
    }

    #[RunInSeparateProcess]
    public function test_asset_in_a_column_is_in_use(): void
    {
        $this->post_('p1', 'full', ['c1' => [], 'c2' => [['_uid' => self::RIGHT, '_block' => 'gallery', 'photos' => [self::PHOTO]]]]);
        $references = $this->app()->container()->get(FolioRecordReferences::class);

        $usages = $references->usagesOf(self::PHOTO);

        $this->assertCount(1, $usages, 'in a column the layout does not show, too: switching back shows it');
        $this->assertSame('post', $usages[0]->type);
        $this->assertSame([self::PHOTO => 1, 'other' => 0], $references->usageCounts([self::PHOTO, 'other']));
    }

    #[RunInSeparateProcess]
    public function test_column_blocks_are_translated(): void
    {
        $this->post_('p1', '1-1', [
            'c1' => [self::prose(self::LEFT, 'Left')],
            'c2' => [],
            'c3' => [self::prose(self::RIGHT, 'Not shown here')],
        ]);
        $record = $this->stored('post', 'p1');
        $status = $this->app()->container()->get(TranslationStatus::class);
        $path = 'sections.' . self::GRID . '.columns.areas.c1.' . self::LEFT . '.caption';

        $missing = array_map(static fn ($t): string => $t->path, $status->missing($record->getType(), $record, 'de'));
        $this->assertContains($path, $missing, 'an untranslated block in a column counts');
        $this->assertNotContains('sections.' . self::GRID . '.columns.areas.c3.' . self::RIGHT . '.caption', $missing, 'a column the layout does not show is not waited for');
        $label = null;
        foreach ($status->texts($record->getType(), $record) as $text) {
            $label = $text->path === $path ? $text->label : $label;
        }
        $this->assertSame('Sections › Grid › Columns › Column 1 › Prose › Caption', $label);

        $source = $this->app()->container()->get(TextSources::class)->get('post');
        $this->assertContains($path, array_map(static fn ($u): string => $u->id, $source->units('p1', 'de', Scope::Needed)));
        $outcome = $source->write('p1', 'de', [$path => 'Links', 'title' => 'Hallo', 'slug' => 'hallo']);
        $this->assertSame('written', $outcome->status->value, (string) $outcome->message);
        $columns = $this->stored('post', 'p1')->get('sections')[0]['columns'];
        $this->assertSame(['en' => 'Left', 'de' => 'Links'], array_intersect_key($columns['areas']['c1'][0]['caption'], ['en' => 1, 'de' => 1]));
        $this->assertSame([self::prose(self::RIGHT, 'Not shown here')], $columns['areas']['c3'], 'the other columns as they were');
        $this->assertSame('1-1', $columns['layout']);
    }

    #[RunInSeparateProcess]
    public function test_a_hidden_column_keeps_its_translation_stamps_and_machine_marks(): void
    {
        $this->post_('p1', '1-1-1', ['c1' => [self::prose(self::LEFT, 'Left')], 'c2' => [], 'c3' => [self::prose(self::RIGHT, 'Right')]]);
        $prefix = 'sections.' . self::GRID . '.columns.areas.';
        $right = $prefix . 'c3.' . self::RIGHT . '.caption';
        $outcome = $this->app()->container()->get(TextSources::class)->get('post')->write('p1', 'de', [$prefix . 'c1.' . self::LEFT . '.caption' => 'Links', $right => 'Rechts', 'title' => 'Hallo', 'slug' => 'hallo']);
        $this->assertSame('written', $outcome->status->value, (string) $outcome->message);
        $status = $this->app()->container()->get(TranslationStatus::class);
        $stamp = $status->stampedFingerprint($this->stored('post', 'p1'), 'de', $right);
        $this->assertNotNull($stamp, 'stamped by the machine translation');

        // Two columns, saved: c3 is kept, not shown; its stamp and machine mark stay with it.
        $this->saveLayout('1-1');
        $narrow = $this->stored('post', 'p1');
        $this->assertSame('1-1', $narrow->get('sections')[0]['columns']['layout']);
        $this->assertSame($stamp, $status->stampedFingerprint($narrow, 'de', $right), 'the hidden column keeps its stamp');

        // Back to three: the column's translation is as it was, still machine-made and not checked.
        $this->saveLayout('1-1-1');
        $back = $this->stored('post', 'p1');
        $this->assertSame($stamp, $status->stampedFingerprint($back, 'de', $right));
        $this->assertContains($right, array_map(static fn ($t): string => $t->path, $status->unchecked($back->getType(), $back, 'de')), 'the machine mark is intact');
        $this->assertNotContains($right, array_map(static fn ($t): string => $t->path, $status->outdated($back->getType(), $back, 'de')), 'and not outdated');
    }

    #[RunInSeparateProcess]
    public function test_rich_text_inside_a_column_is_sanitized(): void
    {
        $this->publisher()->publish($this->working('post', 'p1', [
            'title' => ['en' => 'T'],
            'slug' => ['en' => 'hello'],
            'sections' => [['_uid' => self::GRID, '_block' => 'grid', 'columns' => ['layout' => '1-1', 'areas' => [
                // A quote is data-only: the grid's template prints its text itself (|raw), from the grid's values.
                'c1' => [['_uid' => self::LEFT, '_block' => 'quote', 'text' => ['en' => self::EVIL]]],
                // A prose block renders itself (blocks() in the grid's template).
                'c2' => [['_uid' => self::RIGHT, '_block' => 'prose', 'body' => ['en' => self::EVIL], 'caption' => ['en' => 'Caption'], 'quotes' => []]],
            ]]]],
        ]), null);

        $html = (string) $this->get('/hello')->getBody();

        preg_match('/(Key|Variable|Unknown|Impossible)[^<]{0,200}/', $html, $error);
        $this->assertSame(2, substr_count($html, 'kept'), 'the quote in c1 and the prose in c2 render' . ($error ? ': ' . html_entity_decode($error[0]) : ''));
        $this->assertStringContainsString('<q><p>kept</p></q>', $html, 'the column\'s data-only block reaches the template sanitized');
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('steal()', $html);
    }

    #[RunInSeparateProcess]
    public function test_duplicate_gives_the_blocks_in_its_columns_fresh_uids(): void
    {
        $this->post_('p1', '1-1', ['c1' => [self::prose(self::LEFT, 'Left')], 'c2' => [self::prose(self::RIGHT, 'Right')]]);
        $form = $this->html($this->get('/folio/post/p1/edit'));

        $copy = $this->html($this->post('/folio/_blocks/duplicate?' . http_build_query(['name' => 'sections', 'uid' => self::GRID, 'locale' => 'en', 'depth' => '0', 'type' => 'post', 'id' => 'p1']), self::posted($form)));

        $this->assertStringContainsString('value="Left"', $copy, 'the columns\' blocks are copied');
        $this->assertStringContainsString('value="Right"', $copy);
        foreach ([self::GRID, self::LEFT, self::RIGHT] as $uid) {
            $this->assertStringNotContainsString($uid, $copy, 'every uid is fresh, the columns\' blocks\' too');
        }
        $this->assertMatchesRegularExpression('/name="sections\[[a-f0-9]{16}\]\[columns\]\[areas\]\[c2\]\[_order\]\[\]" value="[a-f0-9]{16}"/', $copy);
    }

    /**
     * A stored post whose sections hold one grid with these columns.
     *
     * @param array<string, list<array<string, mixed>>> $areas
     */
    private function post_(string $id, string $layout, array $areas): void
    {
        $this->working('post', $id, [
            'title' => ['en' => 'Hello'],
            'slug' => ['en' => 'hello'],
            'status' => 'published',
            'sections' => [['_uid' => self::GRID, '_block' => 'grid', 'columns' => ['layout' => $layout, 'areas' => $areas]]],
        ]);
    }

    /** The post's edit form saved in English with the grid's layout set to $layout. */
    private function saveLayout(string $layout): void
    {
        $body = self::posted($this->html($this->get('/folio/post/p1/edit')));
        $body['sections'][self::GRID]['columns']['layout'] = $layout;
        $saved = $this->post('/folio/post/p1', $body);
        $this->assertSame(302, $saved->getStatusCode(), substr((string) $saved->getBody(), 0, 3000));
    }

    /**
     * @param list<array<string, mixed>> $quotes
     * @return array<string, mixed> a prose block
     */
    private static function prose(string $uid, string $caption, array $quotes = []): array
    {
        return ['_uid' => $uid, '_block' => 'prose', 'body' => ['en' => ''], 'caption' => ['en' => $caption], 'quotes' => $quotes];
    }

    /**
     * POST /folio/_areas for the grid's columns as the picker or a Move button sends it (htmx: the whole record form).
     *
     * @param array<string, string> $more
     */
    private function areas(string $form, array $more, string $layout): string
    {
        $body = $more + self::posted($form);
        $body['sections'][self::GRID]['columns']['layout'] = $layout;
        $response = $this->post('/folio/_areas?' . http_build_query(['name' => 'sections[' . self::GRID . '][columns]', 'set' => 'grid', 'type' => 'post', 'id' => 'p1', 'locale' => 'en', 'depth' => '1']), $body);

        return $this->html($response);
    }

    private function html(ResponseInterface $response): string
    {
        $this->assertSame(200, $response->getStatusCode(), substr((string) $response->getBody(), 0, 3000));

        return (string) $response->getBody();
    }

    private function between(string $html, string $from, string $to): string
    {
        $rest = $this->after($html, $from);

        return substr($rest, 0, (int) strpos($rest, $to));
    }

    private function after(string $html, string $from): string
    {
        $at = strpos($html, $from);
        $this->assertNotFalse($at, "\"{$from}\" not found");

        return substr($html, $at);
    }

    private function before(string $html, string $to): string
    {
        $at = strpos($html, $to);

        return $at === false ? $html : substr($html, 0, $at);
    }

    /**
     * What a browser posts for the record form: its controls, skipping buttons, disabled controls
     * and unchecked boxes.
     *
     * @return array<string, mixed>
     */
    private static function posted(string $html): array
    {
        $doc = new \DOMDocument();
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($doc);
        $controls = '(self::input or self::select or self::textarea)';
        $pairs = [];
        foreach ($xpath->query("//form[@id='folio-record']//*[{$controls} and (not(@form) or @form='folio-record')] | //*[{$controls} and @form='folio-record' and not(ancestor::form[@id='folio-record'])]") ?: [] as $el) {
            if (!$el instanceof \DOMElement || $el->getAttribute('name') === '' || $el->hasAttribute('disabled')) {
                continue;
            }
            $type = strtolower($el->getAttribute('type'));
            if ($el->tagName === 'input' && in_array($type, ['button', 'submit', 'reset', 'image', 'file'], true)) {
                continue;
            }
            if ($el->tagName === 'input' && in_array($type, ['checkbox', 'radio'], true) && !$el->hasAttribute('checked')) {
                continue;
            }
            $values = match ($el->tagName) {
                'textarea' => [(string) preg_replace('/^\r?\n/', '', $el->textContent)],
                'select' => self::selected($el),
                default => [$el->getAttribute('value')],
            };
            foreach ($values as $value) {
                $pairs[] = rawurlencode($el->getAttribute('name')) . '=' . rawurlencode($value);
            }
        }
        parse_str(implode('&', $pairs), $body);

        return $body;
    }

    /** @return list<string> */
    private static function selected(\DOMElement $select): array
    {
        $chosen = [];
        foreach ($select->getElementsByTagName('option') as $option) {
            if ($option->hasAttribute('selected')) {
                $chosen[] = $option->getAttribute('value');
            }
        }
        $first = $select->getElementsByTagName('option')->item(0);

        return $chosen === [] && $first !== null ? [$first->getAttribute('value')] : $chosen;
    }
}
