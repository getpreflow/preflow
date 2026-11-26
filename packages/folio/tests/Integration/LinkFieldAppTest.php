<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Context\Contexts;
use Preflow\Data\DynamicRecord;
use Preflow\Fields\RecordAddresses;
use Preflow\Fields\Values\Link;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\Testing\ChecksFrameBalance;
use Preflow\Testing\ContextFactory;
use Preflow\View\TemplateEngineInterface;

/**
 * The link field in Folio (0.91): the editor picks a post through the relation search, a save
 * stores preflow/fields' Link and refuses an invalid address on the field, a page gets the
 * post's current address (none once it is unpublished, and the editor marks the link), it works
 * in a block, and a link its show_when hides reads empty.
 */
final class LinkFieldAppTest extends TestCase
{
    use ChecksFrameBalance;

    use PublishingScaffold;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $this->write('config/blocks.php', "<?php\nreturn ['classes' => [\\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\CallToActionBlock::class]];\n");
        $this->writeModel('teaser', ['label' => 'Teasers', 'publishing' => false, 'fields' => [
            'title' => ['type' => 'string'],
            'cta' => ['type' => 'link', 'label' => 'Button', 'link' => ['types' => ['post']]],
            'kind' => ['type' => 'select', 'options' => ['text' => 'Text', 'link' => 'Link']],
            'more' => ['type' => 'link', 'show_when' => ['field' => 'kind', '=' => 'link']],
            'sections' => ['type' => 'blocks'],
        ]]);
        $this->writeModel('banner', ['label' => 'Banner', 'publishing' => false, 'singleton' => true, 'fields' => [
            'kind' => ['type' => 'select', 'options' => ['text' => 'Text', 'link' => 'Link']],
            'more' => ['type' => 'link', 'show_when' => ['field' => 'kind', '=' => 'link']],
        ]]);
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    /** @param array<string, string>|string $slug one slug, or one per language */
    private function publishPost(string $id, string $title, array|string $slug): void
    {
        $slugs = is_array($slug) ? $slug : ['en' => $slug];
        $this->publisher()->publish($this->working('post', $id, ['title' => ['en' => $title], 'slug' => $slugs, '_locales' => array_keys($slugs)]), null);
    }

    #[RunInSeparateProcess]
    public function test_the_editor_picks_a_post_through_the_relation_search(): void
    {
        $this->publishPost('p1', 'Hello world', 'hello');

        $html = (string) $this->get('/folio/teaser/new')->getBody();
        $this->assertStringContainsString('name="cta[kind]"', $html);
        $this->assertStringContainsString('data-folio-relation-name="cta[record][post]"', $html);
        $this->assertStringContainsString('<input type="hidden" name="cta[type]" value="post">', $html);
        $this->assertStringContainsString('show-when-core.js', $html, 'the kind\'s parts show and hide as conditional fields do');

        // The search endpoint the picker asks for a large type.
        $res = $this->get('/folio/_relation/post?q=hello');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(['items' => [['id' => 'p1', 'label' => 'Hello world']], 'more' => false], json_decode((string) $res->getBody(), true));
    }

    #[RunInSeparateProcess]
    public function test_a_saved_record_link_gets_the_posts_address_and_none_once_unpublished(): void
    {
        $this->publishPost('p1', 'Hello world', ['en' => 'hello', 'de' => 'hallo']);

        $res = $this->post('/folio/teaser', ['uuid' => 't1', 'title' => 'T', 'cta' => ['kind' => 'record', 'type' => 'post', 'record' => ['post' => 'p1'], 'label' => 'Read', 'new_tab' => '1']]);
        $this->assertSame(302, $res->getStatusCode(), (string) $res->getBody());
        $teaser = $this->dm()->queryType('teaser')->get()->items()[0];
        $this->assertEquals(Link::toRecord('post', 'p1', ['en' => 'Read'], newTab: true), Link::fromStored($teaser->get('cta')));

        $this->assertSame('<a href="/hello" target="_blank" rel="noopener noreferrer">Read</a>', $this->page('<a{{ link_attrs(cta) }}>{{ link_label(cta) }}</a>', ['cta' => $teaser->get('cta')]));
        $this->assertSame('/de/hallo', $this->page('{{ link_href(cta, "de") }}', ['cta' => $teaser->get('cta')]), 'the record\'s address in that language');

        $this->publisher()->unpublish('post', 'p1');
        $this->assertSame('<a>Read</a>', $this->page('<a{{ link_attrs(cta) }}>{{ link_label(cta) }}</a>', ['cta' => $teaser->get('cta')]), 'unpublished: no href');
        $html = (string) $this->get('/folio/teaser/' . $teaser->getId() . '/edit')->getBody();
        $this->assertStringContainsString('<p class="folio-link-broken" role="status">Linked page no longer available.', $html);
    }

    #[RunInSeparateProcess]
    public function test_a_save_refuses_an_invalid_address_on_the_field(): void
    {
        $res = $this->post('/folio/teaser', ['title' => 'T', 'cta' => ['kind' => 'url', 'url' => 'javascript:alert(1)']]);

        $this->assertSame(422, $res->getStatusCode());
        $body = (string) $res->getBody();
        $this->assertMatchesRegularExpression('~<fieldset class="form-group folio-link has-error" data-folio-link>.*?<div class="form-error">Must be a web address starting with https:// or http://, or a path on this site starting with /.</div>~s', $body);
        $this->assertSame([], $this->dm()->queryType('teaser')->get()->items());

        $res = $this->post('/folio/teaser', ['title' => 'T', 'cta' => ['kind' => 'email', 'email' => 'team@example.org']]);
        $this->assertSame(302, $res->getStatusCode(), (string) $res->getBody());
    }

    #[RunInSeparateProcess]
    public function test_works_as_a_block_field(): void
    {
        $this->publishPost('p1', 'Hello world', 'hello');
        $uid = 'aaaaaaaaaaaaaaaa';
        $this->dm()->saveType(DynamicRecord::fromArray($this->dm()->getTypeRegistry()->get('teaser'), ['uuid' => 't1', 'title' => 'T', 'sections' => [
            ['_uid' => $uid, '_block' => 'call_to_action', 'heading' => 'Go', 'button' => null],
        ]]), validate: false);

        $html = (string) $this->get('/folio/teaser/t1/edit')->getBody();
        $this->assertStringContainsString('name="sections[' . $uid . '][button][kind]"', $html);
        $this->assertStringContainsString('data-folio-relation-name="sections[' . $uid . '][button][record][post]"', $html);
        $this->assertStringContainsString('&quot;prefix&quot;:&quot;sections[' . $uid . '][button]&quot;', $html);
        $this->assertStringNotContainsString('name="sections[' . $uid . '][button][email]"', $html, 'the block offers record and url only');

        $posted = ['title' => 'T', 'sections' => ['_order' => [$uid], $uid => ['_block' => 'call_to_action', 'heading' => 'Go', 'button' => ['kind' => 'url', 'url' => 'example.org']]]];
        $res = $this->post('/folio/teaser/t1', $posted);
        $this->assertSame(422, $res->getStatusCode(), 'the block\'s link is checked too');
        $this->assertStringContainsString('Must be a web address starting with https://', (string) $res->getBody());

        $posted['sections'][$uid]['button'] = ['kind' => 'record', 'type' => 'post', 'record' => ['post' => 'p1'], 'label' => 'Go there'];
        $res = $this->post('/folio/teaser/t1', $posted);
        $this->assertSame(302, $res->getStatusCode(), (string) $res->getBody());
        $button = $this->stored('teaser', 't1')->get('sections')[0]['button'];
        $this->assertEquals(Link::toRecord('post', 'p1', ['en' => 'Go there']), Link::fromStored($button));
        $this->assertSame('/hello', $this->page('{{ link_href(button) }}', ['button' => $button]));
    }

    #[RunInSeparateProcess]
    public function test_a_link_its_show_when_hides_keeps_its_value_and_reads_empty(): void
    {
        $more = ['kind' => 'url', 'target' => 'https://example.org', 'label' => 'More', 'new_tab' => false];
        $this->dm()->saveType(DynamicRecord::fromArray($this->dm()->getTypeRegistry()->get('banner'), ['uuid' => 'banner', 'kind' => 'link', 'more' => $more]), validate: false);

        // Switched so it hides, posted with a value that would not pass: saved, the link kept.
        $res = $this->post('/folio/banner/banner', ['kind' => 'text', 'more' => ['kind' => 'url', 'url' => 'javascript:alert(1)']]);
        $this->assertSame(302, $res->getStatusCode(), (string) $res->getBody());
        $this->assertSame($more, Link::decode($this->stored('banner', 'banner')->get('more')));

        $singletons = $this->app()->container()->get(\Preflow\Folio\Content\Singletons::class);
        $values = Contexts::run(ContextFactory::forTest()->in('en'), static fn (): ?array => $singletons->get('banner'));
        $this->assertNull($values['more'] ?? null, 'folio_get reads the hidden link as empty');
    }

    #[RunInSeparateProcess]
    public function test_a_file_link_picks_a_library_file_and_is_marked_once_it_is_gone(): void
    {
        $app = $this->app();
        $image = imagecreatetruecolor(40, 30);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();
        $library = $app->container()->get(\Preflow\Media\MediaLibrary::class);
        $asset = $library->upload($f->createUploadedFile($f->createStream($png), strlen($png), UPLOAD_ERR_OK, 'prices.png'), null);

        // Chosen in the picker: the selection posts {name}[asset_ids][].
        $res = $this->post('/folio/teaser', ['uuid' => 't1', 'title' => 'T', 'cta' => ['kind' => 'asset', 'asset_ids' => ['', $asset->id], 'label' => 'Prices']]);
        $this->assertSame(302, $res->getStatusCode(), (string) $res->getBody());
        $teaser = $this->dm()->queryType('teaser')->get()->items()[0];
        $this->assertEquals(Link::toAsset($asset->id, ['en' => 'Prices']), Link::fromStored($teaser->get('cta')));

        $html = (string) $this->get('/folio/teaser/' . $teaser->getId() . '/edit')->getBody();
        $this->assertStringContainsString('data-selection-name="cta[asset_ids][]"', $html);
        $this->assertStringContainsString('<input type="hidden" name="cta[asset_ids][]" value="' . $asset->id . '">', $html);
        $this->assertStringContainsString('prices.png', $html, 'the picked file\'s card names it');
        $this->assertStringContainsString('class="folio-asset-thumb"', $html, 'with its thumbnail');
        $this->assertStringNotContainsString('folio-link-broken', $html);
        $this->assertSame($library->url($library->find($asset->id)), $this->page('{{ link_href(cta) }}', ['cta' => $teaser->get('cta')]));

        // An id that is no file is not taken: the check names the missing target.
        $res = $this->post('/folio/teaser/' . $teaser->getId(), ['title' => 'T', 'cta' => ['kind' => 'asset', 'asset_ids' => ['', 'nope']]]);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('Choose or enter what the link points to.', (string) $res->getBody());

        $library->delete($asset->id);
        $html = (string) $this->get('/folio/teaser/' . $teaser->getId() . '/edit')->getBody();
        $this->assertStringContainsString('<p class="folio-link-broken" role="status">Linked file no longer available.', $html);
        $this->assertSame('', $this->page('{{ link_href(cta) }}', ['cta' => $teaser->get('cta')]), 'the mark and the page agree: no href');
    }

    #[RunInSeparateProcess]
    public function test_a_core_registered_rule_applies_in_folio_without_the_field_type_returning_it(): void
    {
        // A field type's checks registered with the data package alone (FieldKinds::rules()), as
        // preflow/fields does; the Folio field type (here: string) returns none of them.
        $this->writeModel('note2', ['label' => 'Notes', 'publishing' => false, 'fields' => ['code' => ['type' => 'string', 'validate' => ['required']], 'sections' => ['type' => 'blocks']]]);
        $app = $this->app();
        $app->container()->get(\Preflow\Data\DataManager::class)->fieldKinds()->rules('string', static fn (array $config): array => ['regex:/^[A-Z]*$/']);
        $this->assertSame([], $app->container()->get(\Preflow\Folio\Field\FieldTypeRegistry::class)->get('string')->rules([]));
        $handle = static fn (string $uri, array $body) => $app->handle((new \Nyholm\Psr7\Factory\Psr17Factory())->createServerRequest('POST', $uri)->withHeader('Sec-Fetch-Site', 'same-origin')->withParsedBody($body));

        $res = $handle('/folio/note2', ['code' => 'lower']);
        $this->assertSame(422, $res->getStatusCode(), 'the record\'s field');
        $this->assertStringContainsString('Format is invalid.', (string) $res->getBody());

        // Inside a block too: the call-to-action block's heading is a string field.
        $uid = 'bbbbbbbbbbbbbbbb';
        $res = $handle('/folio/note2', ['code' => 'UP', 'sections' => ['_order' => [$uid], $uid => ['_block' => 'call_to_action', 'heading' => 'lower']]]);
        $this->assertSame(422, $res->getStatusCode(), 'a block\'s field');
        $res = $handle('/folio/note2', ['code' => 'UP', 'sections' => ['_order' => [$uid], $uid => ['_block' => 'call_to_action', 'heading' => 'GO']]]);
        $this->assertSame(302, $res->getStatusCode(), (string) $res->getBody());
    }

    #[RunInSeparateProcess]
    public function test_transfer_carries_a_stored_link_unchanged(): void
    {
        $cta = ['kind' => 'record', 'target' => ['type' => 'post', 'id' => 'p1'], 'label' => ['en' => 'Read', 'de' => 'Lesen'], 'new_tab' => true];
        $this->working('teaser', 't1', ['title' => 'T', 'cta' => $cta]);
        $app = $this->app();
        $dm = $app->container()->get(\Preflow\Data\DataManager::class);
        $stream = fopen('php://temp', 'w+b');
        (new \Preflow\Folio\Transfer\Exporter(\Preflow\Folio\Transfer\ContentSet::fromApp($app), $dm))->export($stream);
        rewind($stream);

        $dm->deleteType('teaser', 't1');
        $this->assertNull($dm->findType('teaser', 't1'));
        $importer = new \Preflow\Folio\Transfer\Importer(\Preflow\Folio\Transfer\ContentSet::fromApp($app), $dm);
        $plan = $importer->read($stream);
        try {
            $importer->apply($plan);
        } finally {
            $plan->cleanup();
        }

        $this->assertSame($cta, Link::decode($this->stored('teaser', 't1')->get('cta')));
    }

    /** @param array<string, mixed> $context */
    private function page(string $template, array $context): string
    {
        // A file per template: the engine keeps a template it compiled once.
        $name = '_link_probe_' . md5($template) . '.twig';
        $this->write('app/pages/' . $name, $template);
        $app = $this->app();
        $this->assertNotNull($app->container()->get(RecordAddresses::class));

        return Contexts::run(
            ContextFactory::forTest()->in('en'),
            static fn (): string => $app->container()->get(TemplateEngineInterface::class)->render($name, $context),
        );
    }
}
