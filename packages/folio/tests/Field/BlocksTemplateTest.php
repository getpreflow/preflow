<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Field;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Locales;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\Types\BlocksFieldType;
use Preflow\Folio\Tests\Support\PublishingScaffold;

/**
 * The blocks editor's markup (0.75, board PageEditor): block cards with the family tile, the "…"
 * menu and the collapse chevron, the error and unknown states, the "Sections · N blocks" heading
 * with Collapse all, and the "Add block" picker with its search.
 */
final class BlocksTemplateTest extends TestCase
{
    use PublishingScaffold;

    private const string A = 'aaaaaaaaaaaaaaaa';
    private const string B = 'bbbbbbbbbbbbbbbb';
    private const string C = 'cccccccccccccccc';

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    /**
     * @param list<array<string, mixed>>  $blocks
     * @param array<string, list<string>> $nestedErrors
     * @param array<string, mixed>        $config
     */
    private function render(array $blocks, array $nestedErrors = [], array $config = []): string
    {
        $type = $this->app()->container()->get(FieldTypeRegistry::class)->get('blocks');
        $this->assertInstanceOf(BlocksFieldType::class, $type);

        return $type->renderEditor(new FieldContext(
            name: 'sections',
            label: 'Sections',
            value: $blocks,
            config: $config,
            nestedErrors: $nestedErrors,
            locale: 'en',
            locales: Locales::single('en'),
        ));
    }

    /** The markup of one card, up to the next card or the picker. */
    private static function card(string $html, string $uid): string
    {
        $start = strpos($html, 'id="block-' . $uid . '"');
        self::assertNotFalse($start, 'card ' . $uid);
        $ends = array_filter([strpos($html, '<div class="folio-block-card', $start), strpos($html, 'data-blocks-picker', $start)], 'is_int');

        return substr($html, $start, $ends === [] ? null : min($ends) - $start);
    }

    public function test_the_card_tile_carries_the_block_family(): void
    {
        $html = $this->render([
            ['_uid' => self::A, '_block' => 'hero', 'headline' => 'Your models, editable'],
            ['_uid' => self::B, '_block' => 'link', 'label' => 'Docs', 'url' => '/docs'],
        ]);

        // #[Block(family: TypeFamily::Page)] on the hero fixture; the link block declares none (a neutral tile).
        $this->assertMatchesRegularExpression('#<span class="folio-type-icon" data-family="page" aria-hidden="true"><svg class="folio-icon"><use href="[^"]*\#block"></use></svg></span>\s*<span class="folio-block-label">Hero</span>#', self::card($html, self::A));
        $this->assertMatchesRegularExpression('#<span class="folio-type-icon" data-family="" aria-hidden="true"><svg class="folio-icon"><use href="[^"]*\#link"></use></svg></span>\s*<span class="folio-block-label">Link</span>#', self::card($html, self::B));
        // The one-line summary is the first text field, as before.
        $this->assertStringContainsString('<span class="folio-block-summary">Your models, editable</span>', self::card($html, self::A));
    }

    public function test_the_card_menu_holds_move_duplicate_and_remove_then_the_chevron(): void
    {
        $card = self::card($this->render([['_uid' => self::A, '_block' => 'hero', 'headline' => 'H']]), self::A);

        $this->assertMatchesRegularExpression('#<details class="folio-row-menu folio-block-menu" data-folio-row-menu>\s*<summary class="btn btn-ghost btn-icon btn-sm" aria-label="Actions for Hero" title="Actions for Hero" aria-haspopup="menu">#', $card);
        $this->assertMatchesRegularExpression('#<div class="folio-menu folio-row-menu-panel" role="menu" aria-label="Actions for Hero">#', $card);
        preg_match_all('#<button type="button" class="folio-menu-item[^"]*" role="menuitem" (data-block-[a-z]+)#', $card, $items);
        $this->assertSame(['data-block-up', 'data-block-down', 'data-block-duplicate', 'data-block-remove'], $items[1]);
        // The existing duplicate request, unchanged; Remove is the danger item after a divider.
        $this->assertMatchesRegularExpression('#data-block-duplicate\s+hx-post="/folio/_blocks/duplicate\?name=sections&amp;uid=' . self::A . '[^"]*" hx-include="closest \[data-block-card\], \[name=_csrf_token\]"\s+hx-target="closest \[data-block-card\]" hx-swap="afterend" hx-disabled-elt="this">#', $card);
        $this->assertMatchesRegularExpression('#<div class="folio-menu-sep" role="separator"></div>\s*<button type="button" class="folio-menu-item is-danger" role="menuitem" data-block-remove>#', $card);
        // After the menu: the collapse chevron, the card's one toggle, named by the block.
        $this->assertMatchesRegularExpression('#</details>\s*<button type="button" class="btn btn-ghost btn-icon btn-sm folio-block-toggle" data-block-collapse aria-expanded="false"><svg class="folio-icon folio-block-chevron" aria-hidden="true">[^<]*<use[^>]*\#ui-chevron"></use></svg><span class="folio-sr">Hero</span></button>#', $card);
        $this->assertSame(1, substr_count($card, 'data-block-collapse'));
    }

    public function test_a_label_with_an_ampersand_is_escaped_once_in_the_menu(): void
    {
        $this->write('config/blocks.php', "<?php\nreturn ['classes' => [\\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\QaBlock::class]];\n");

        $card = self::card($this->render([['_uid' => self::A, '_block' => 'qa', 'question' => 'Why?']]), self::A);

        $this->assertStringContainsString('aria-label="Actions for Q&amp;A" title="Actions for Q&amp;A"', $card);
        $this->assertStringContainsString('role="menu" aria-label="Actions for Q&amp;A"', $card);
        $this->assertStringNotContainsString('&amp;amp;', $card);
    }

    public function test_an_invalid_card_is_open_with_the_message_in_the_summary_slot(): void
    {
        $card = self::card($this->render(
            [['_uid' => self::A, '_block' => 'hero', 'headline' => '', 'intro' => 'Some intro']],
            [self::A . '.headline' => ['This field is required.']],
        ), self::A);

        $this->assertMatchesRegularExpression('#^id="block-' . self::A . '"#', $card);
        $this->assertStringContainsString('<span class="folio-block-summary is-error">Headline: This field is required.</span>', $card);
        $this->assertStringNotContainsString('>Some intro</span>', $card, 'the error takes the summary slot');
        $this->assertStringContainsString('aria-expanded="true"', $card);
    }

    public function test_the_card_class_list_marks_open_and_error(): void
    {
        $html = $this->render(
            [['_uid' => self::A, '_block' => 'hero', 'headline' => '']],
            [self::A . '.headline' => ['This field is required.']],
        );

        $this->assertStringContainsString('<div class="folio-block-card is-open has-error" id="block-' . self::A . '"', $html);
    }

    public function test_an_unknown_block_is_a_dashed_card_with_a_warning_and_remove(): void
    {
        $card = self::card($this->render([['_uid' => self::C, '_block' => 'gone', 'foo' => 'bar']]), self::C);

        $this->assertStringContainsString('<span class="folio-type-icon is-warning" aria-hidden="true"><svg class="folio-icon"><use href=', $card);
        $this->assertStringContainsString('#ui-warn"></use>', $card);
        $this->assertStringContainsString('Unknown block “gone”', $card);
        $this->assertMatchesRegularExpression('#<button type="button" class="btn btn-ghost btn-sm" data-tone="danger" data-block-remove>[^<]*<svg class="folio-icon" aria-hidden="true"><use[^>]*\#ui-trash"></use></svg><span>Remove</span></button>#', $card);
        $this->assertStringContainsString('name="sections[' . self::C . '][_raw]"', $card, 'the stored data still goes back');
    }

    public function test_a_field_nested_too_deep_is_a_dashed_card_with_a_warning(): void
    {
        $type = $this->app()->container()->get(FieldTypeRegistry::class)->get('blocks');
        $method = new \ReflectionMethod($type, 'renderField');
        $html = $method->invoke($type, 'x', 'Links', null, [], [], [], [], Locales::single('en'), 'en', 99);

        $this->assertMatchesRegularExpression('#^<div class="folio-blocks is-too-deep">\s*<span class="folio-type-icon is-warning" aria-hidden="true"><svg class="folio-icon"><use[^>]*\#ui-warn"></use></svg></span>#', $html);
        $this->assertStringContainsString('Nested too deep to edit here.', $html);
    }

    public function test_the_heading_counts_the_blocks_and_offers_collapse_all(): void
    {
        $html = $this->render([
            ['_uid' => self::A, '_block' => 'hero', 'headline' => 'H'],
            ['_uid' => self::B, '_block' => 'link', 'label' => 'L'],
        ]);

        $this->assertMatchesRegularExpression('#<div class="folio-blocks-head">\s*<span class="form-label">Sections <span class="folio-blocks-count" data-blocks-count data-one=":count block" data-other=":count blocks">· 2 blocks</span></span>#', $html);
        $this->assertMatchesRegularExpression('#<button type="button" class="folio-blocks-collapse-all" data-blocks-collapse-all data-text-collapse="Collapse all" data-text-expand="Expand all" hidden>Expand all</button>#', $html);
    }

    public function test_the_picker_is_a_button_with_a_searchable_popover(): void
    {
        $html = $this->render([]);

        $this->assertMatchesRegularExpression('#<button type="button" class="btn btn-secondary folio-blocks-open" data-blocks-picker-open aria-haspopup="dialog" aria-expanded="false" aria-controls="(folio-picker-[a-f0-9]+)" hidden>#', $html);
        preg_match('#aria-controls="(folio-picker-[a-f0-9]+)"#', $html, $m);
        // A named group without script (the groups listed in place); blocks.js makes it role="dialog".
        $this->assertMatchesRegularExpression('#<div class="folio-blocks-popover" id="' . $m[1] . '" role="group" aria-label="Add block" data-blocks-popover>#', $html);
        // The search (three block types here), not a record field: no name, never an edit of the record.
        $this->assertMatchesRegularExpression('#<input type="search" class="folio-blocks-search-input" data-blocks-search data-folio-no-edit placeholder="Add a block — type to filter 3" aria-label="Add a block — type to filter 3" autocomplete="off">#', $html);
        // Each item: the type tile and its label, on the existing fragment request; searchable by label and description.
        $this->assertMatchesRegularExpression('#<button type="button" class="folio-blocks-add" data-blocks-item data-search="hero big opening section"\s+hx-get="/folio/_blocks/hero/new\?name=sections&amp;locale=en&amp;depth=0" hx-target="closest \[data-blocks-picker\]" hx-swap="beforebegin" hx-disabled-elt="this"\s+title="Big opening section">\s*<span class="folio-type-icon" data-family="page" aria-hidden="true">#', $html);
        $this->assertMatchesRegularExpression('#<span class="folio-blocks-group-label" id="[^"]+">Sections</span>\s*<div class="folio-blocks-items" role="group" aria-labelledby="#', $html);
        $this->assertMatchesRegularExpression('#<p class="folio-blocks-empty" data-blocks-empty data-text="No block matches “:query”." hidden></p>#', $html);
    }

    public function test_a_field_with_one_block_type_keeps_its_single_button(): void
    {
        $html = $this->render([], [], ['allowed' => ['link']]);

        $this->assertStringNotContainsString('data-blocks-popover', $html);
        $this->assertStringNotContainsString('data-blocks-search', $html);
        $this->assertMatchesRegularExpression('#<button type="button" class="btn btn-secondary folio-blocks-add"\s+hx-get="/folio/_blocks/link/new\?#', $html);
        $this->assertStringContainsString('Add Link', $html);
    }
}
