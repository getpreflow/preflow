<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\Testing\ChecksFrameBalance;

/**
 * Conditional fields in the editor (show_when, 0.91): the form renders a hidden field hidden, with the
 * rule fields.js reads; a save ignores what is posted for a hidden field and keeps its stored value,
 * so switching the source back brings it back; blocks decide per block, also in folio_get.
 */
final class ShowWhenEditorTest extends TestCase
{
    use ChecksFrameBalance;

    use PublishingScaffold;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $this->write('config/blocks.php', "<?php\nreturn ['classes' => [\\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\OfferBlock::class, \\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\OfferGroupBlock::class]];\n");
        $fields = [
            'title' => ['type' => 'string'],
            'kind' => ['type' => 'select', 'options' => ['text' => 'Text', 'link' => 'Link']],
            'button_url' => ['type' => 'string', 'validate' => ['required'], 'show_when' => ['field' => 'kind', '=' => 'link']],
            'sections' => ['type' => 'blocks'],
        ];
        $this->writeModel('teaser', ['label' => 'Teasers', 'publishing' => false, 'fields' => $fields]);
        $this->writeModel('banner', ['label' => 'Banner', 'publishing' => false, 'singleton' => true, 'fields' => $fields]);
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    private function seed(string $type, string $id, array $values): void
    {
        $this->dm()->saveType(DynamicRecord::fromArray($this->dm()->getTypeRegistry()->get($type), ['uuid' => $id] + $values), validate: false);
    }

    #[RunInSeparateProcess]
    public function test_save_keeps_stored_value_of_hidden_field(): void
    {
        $this->seed('teaser', 't1', ['title' => 'One', 'kind' => 'link', 'button_url' => '/a']);

        // Switched so it hides, posted without it: saved (required does not apply), the value kept.
        $res = $this->post('/folio/teaser/t1', ['title' => 'One', 'kind' => 'text']);
        $this->assertSame(302, $res->getStatusCode(), (string) $res->getBody());
        $this->assertSame('text', $this->stored('teaser', 't1')->get('kind'));
        $this->assertSame('/a', $this->stored('teaser', 't1')->get('button_url'));

        // A value posted for the hidden field (no script, or not through the form) is ignored too.
        $this->post('/folio/teaser/t1', ['title' => 'One', 'kind' => 'text', 'button_url' => '/evil']);
        $this->assertSame('/a', $this->stored('teaser', 't1')->get('button_url'));

        // The editor renders it hidden, with its value, for fields.js to show when kind turns link.
        $html = (string) $this->get('/folio/teaser/t1/edit')->getBody();
        $this->assertMatchesRegularExpression('#<div class="folio-field-wrap" data-folio-show-when="[^"]*" hidden>\s*<div[^>]*>.*?name="button_url"[^>]*value="/a"#s', $html);
        $this->assertStringContainsString('show-when-core.js', $html);

        // Switched back: the editor shows /a.
        $this->post('/folio/teaser/t1', ['title' => 'One', 'kind' => 'link', 'button_url' => '/a']);
        $html = (string) $this->get('/folio/teaser/t1/edit')->getBody();
        $this->assertMatchesRegularExpression('#<div class="folio-field-wrap" data-folio-show-when="[^"]*">\s*<div[^>]*>.*?name="button_url"[^>]*value="/a"#s', $html);
        $this->assertSame('/a', $this->stored('teaser', 't1')->get('button_url'));
    }

    #[RunInSeparateProcess]
    public function test_a_hidden_block_field_keeps_its_value_and_reads_empty_in_folio_get(): void
    {
        $a = 'aaaaaaaaaaaaaaaa';
        $b = 'bbbbbbbbbbbbbbbb';
        $this->seed('banner', 'banner', ['title' => 'B', 'kind' => 'text', 'sections' => [
            ['_uid' => $a, '_block' => 'offer', 'kind' => 'link', 'button_url' => '/a'],
            ['_uid' => $b, '_block' => 'offer', 'kind' => 'link', 'button_url' => '/b'],
        ]]);

        $res = $this->post('/folio/banner/banner', ['title' => 'B', 'kind' => 'text', 'sections' => [
            '_order' => [$a, $b],
            $a => ['_block' => 'offer', 'kind' => 'text'],
            $b => ['_block' => 'offer', 'kind' => 'link', 'button_url' => '/b2'],
        ]]);
        $this->assertSame(302, $res->getStatusCode(), (string) $res->getBody());
        $sections = $this->stored('banner', 'banner')->get('sections');
        $this->assertSame('/a', $sections[0]['button_url'], 'the hidden block field keeps its stored value');
        $this->assertSame('/b2', $sections[1]['button_url']);

        // folio_get('banner'): Singletons::get().
        $singletons = $this->app()->container()->get(\Preflow\Folio\Content\Singletons::class);
        $values = \Preflow\Core\Context\Contexts::run(\Preflow\Testing\ContextFactory::forTest()->in('en'), static fn (): ?array => $singletons->get('banner'));
        $out = '[' . ($values['sections'][0]['button_url'] ?? 'null') . '|' . ($values['sections'][1]['button_url'] ?? 'null') . ']';
        $this->assertSame('[null|/b2]', $out, 'each block decides by its own source');
    }

    #[RunInSeparateProcess]
    public function test_search_finds_no_record_through_a_hidden_block_field(): void
    {
        // Loose search reads a type's text kinds; a blocks field is not one (BlocksFieldType is no
        // SearchableText), so no block text is searched, hidden or not.
        $this->seed('teaser', 't1', ['title' => 'One', 'kind' => 'text', 'sections' => [
            ['_uid' => 'aaaaaaaaaaaaaaaa', '_block' => 'offer', 'kind' => 'text', 'button_url' => '/zebrapath'],
        ]]);

        $this->assertSame([], $this->app()->container()->get(\Preflow\Data\Search\RecordSearch::class)->search('zebrapath', ['teaser']));
    }

    #[RunInSeparateProcess]
    public function test_a_read_only_system_field_carries_its_rule_too(): void
    {
        $this->writeModel('offer', ['label' => 'Offers', 'publishing' => false, 'fields' => [
            'kind' => ['type' => 'select', 'options' => ['text' => 'Text', 'link' => 'Link']],
            'checked_url' => ['type' => 'string', 'readonly' => true, 'panel' => 'side', 'show_when' => ['field' => 'kind', '=' => 'link']],
        ]]);
        $this->seed('offer', 'o1', ['kind' => 'text', 'checked_url' => '/checked']);

        $html = (string) $this->get('/folio/offer/o1/edit')->getBody();

        $this->assertMatchesRegularExpression('#<div data-folio-show-when="[^"]*" hidden><dt>Checked Url</dt><dd>/checked</dd>#', $html);
    }

    #[RunInSeparateProcess]
    public function test_a_type_without_show_when_renders_no_rule_and_no_script(): void
    {
        $this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello']]);
        $this->write('config/blocks.php', "<?php\nreturn ['classes' => [\\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\LinkBlock::class]];\n");

        $html = (string) $this->get('/folio/post/p1/edit')->getBody();

        $this->assertStringNotContainsString('data-folio-show-when', $html);
        $this->assertStringNotContainsString('show-when-core.js', $html);
    }
}
