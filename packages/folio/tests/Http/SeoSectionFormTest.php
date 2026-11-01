<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Publishing\PublishState;
use Preflow\Folio\Seo\SeoSection;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\Data\Migration\MigrationRegistry;

/**
 * The editor's "SEO & sharing" section: shown only with SEO on, for a type with a frontend
 * route and the site singleton; saved as the reserved `_seo` meta (SQLite: JSON text), kept
 * when a form does not post it, and content for publishing.
 */
final class SeoSectionFormTest extends TestCase
{
    use PublishingScaffold;

    protected function setUp(): void
    {
        $this->scaffoldPublishing('sqlite');
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    private function seoOn(): void
    {
        $this->write('config/seo.php', "<?php\nreturn ['enabled' => true];\n");
    }

    private function migrate(): void
    {
        ($this->app()->container()->get(MigrationRegistry::class)->steps()['folio.tables'] ?? throw new \RuntimeException('step not registered'))();
    }

    #[RunInSeparateProcess]
    public function test_no_section_while_seo_is_off(): void
    {
        $this->migrate();
        $this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello']]);

        $body = (string) $this->get('/folio/post/p1/edit')->getBody();

        $this->assertStringContainsString('folio-visibility', $body, 'the form itself renders');
        $this->assertStringNotContainsString('folio-seo', $body);
        $this->assertStringNotContainsString('_seo', $body);
        $this->assertStringNotContainsString('folio-seo', (string) $this->get('/folio/site')->getBody());
    }

    #[RunInSeparateProcess]
    public function test_section_on_a_routed_type_and_the_site_singleton_only(): void
    {
        $this->seoOn();
        $this->migrate();
        $this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello']]);
        $this->working('note', 'n1', ['name' => 'A note']);

        $post = (string) $this->get('/folio/post/p1/edit?locale=de')->getBody();
        $this->assertMatchesRegularExpression('~<details class="folio-seo">~', $post, 'closed while nothing is set');
        $this->assertStringContainsString('<summary>SEO & sharing</summary>', $post);
        $this->assertStringContainsString('name="_seo_submitted" value="1"', $post);
        $this->assertStringContainsString('name="_seo[title]"', $post);
        $this->assertStringContainsString('name="_seo[description]"', $post);
        $this->assertStringContainsString('name="_seo[image_ids][]"', $post, 'the asset picker, named for the section');
        $this->assertStringContainsString('name="_seo[noindex]"', $post);
        $this->assertStringContainsString('name="_seo[exclude_sitemap]"', $post);
        $this->assertStringContainsString('name="_seo[exclude_llms]"', $post);
        $this->assertStringContainsString('Leave out of AI index (llms.txt)', $post);
        $this->assertStringContainsString("empty fields use the page's own content", $post);
        $this->assertStringContainsString('enctype="multipart/form-data"', $post);
        $this->assertLessThan(strpos($post, 'folio-seo'), strpos($post, 'folio-visibility'), 'after "Visible in"');

        $this->assertStringContainsString('class="folio-seo"', (string) $this->get('/folio/site')->getBody());
        $this->assertStringNotContainsString('folio-seo', (string) $this->get('/folio/note/n1/edit')->getBody(), 'no frontend route');
    }

    #[RunInSeparateProcess]
    public function test_saving_stores_the_section_for_the_edited_language(): void
    {
        $this->seoOn();
        $this->migrate();
        $this->working('post', 'p1', ['title' => ['en' => 'Hello', 'de' => 'Hallo'], 'slug' => ['en' => 'hello', 'de' => 'hallo'], '_locales' => ['en', 'de'],
            '_seo' => ['title' => ['en' => 'Share EN'], 'description' => ['en' => 'About EN']]]);

        $res = $this->post('/folio/post/p1', ['title' => 'Hallo', 'slug' => 'hallo', '_locale' => 'de', '_seo_submitted' => '1',
            '_seo' => ['title' => 'Teilen DE', 'description' => '', 'noindex' => '1', 'exclude_llms' => '1']]);

        $this->assertSame(302, $res->getStatusCode());
        $seo = SeoSection::fromRecord($this->stored('post', 'p1'));
        $this->assertSame('Share EN', $seo->title('en'));
        $this->assertSame('Teilen DE', $seo->title('de'));
        $this->assertSame('About EN', $seo->description('en'));
        $this->assertSame('', $seo->description('de'));
        $this->assertTrue($seo->noindex());
        $this->assertTrue($seo->inSitemap());
        $this->assertFalse($seo->inLlms());

        $body = (string) $this->get('/folio/post/p1/edit?locale=de')->getBody();
        $this->assertStringContainsString('<details class="folio-seo" open>', $body, 'open once something is set');
        $this->assertStringContainsString('value="Teilen DE"', $body);
    }

    #[RunInSeparateProcess]
    public function test_the_share_image_is_chosen_like_an_asset_fields(): void
    {
        $this->seoOn();
        $this->migrate();
        $this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello']]);
        $file = '2026/10/' . str_repeat('e', 32) . '.png';
        @mkdir($this->dir . '/storage/uploads/2026/10', 0777, true);
        touch($this->dir . '/storage/uploads/' . $file);
        $form = ['title' => 'Hello', 'slug' => 'hello', '_locale' => 'en', '_seo_submitted' => '1'];

        $this->post('/folio/post/p1', $form + ['_seo' => ['image_ids' => ['', '../../etc/passwd', $file]]]);
        $this->assertSame($file, SeoSection::fromRecord($this->stored('post', 'p1'))->image(), 'only a file that exists');
        $this->assertStringContainsString('name="_seo[image_ids][]" value="' . $file . '"', (string) $this->get('/folio/post/p1/edit')->getBody());

        $this->post('/folio/post/p1', $form + ['_seo' => ['title' => 'T']]);
        $this->assertSame($file, SeoSection::fromRecord($this->stored('post', 'p1'))->image(), 'no selection posted: kept');

        $this->post('/folio/post/p1', $form + ['_seo' => ['image_ids' => ['']]]);
        $this->assertNull(SeoSection::fromRecord($this->stored('post', 'p1'))->image(), 'emptied');
    }

    #[RunInSeparateProcess]
    public function test_the_site_singleton_stores_its_section(): void
    {
        $this->seoOn();
        $this->migrate();

        $this->assertSame(302, $this->post('/folio/site/site', ['tagline' => 'Hi', '_locale' => 'en', '_seo_submitted' => '1', '_seo' => ['description' => 'Our site', 'exclude_sitemap' => '1']])->getStatusCode());

        $seo = SeoSection::fromRecord($this->stored('site', 'site'));
        $this->assertSame('Our site', $seo->description('en'));
        $this->assertFalse($seo->inSitemap());
    }

    #[RunInSeparateProcess]
    public function test_a_form_without_the_section_keeps_the_stored_one(): void
    {
        $this->seoOn();
        $this->migrate();
        $this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello'], '_seo' => ['title' => ['en' => 'Kept'], 'noindex' => true]]);

        $this->assertSame(302, $this->post('/folio/post/p1', ['title' => 'Changed', 'slug' => 'hello', '_locale' => 'en', '_seo' => ['title' => 'Ignored']])->getStatusCode());

        $seo = SeoSection::fromRecord($this->stored('post', 'p1'));
        $this->assertSame('Kept', $seo->title('en'));
        $this->assertTrue($seo->noindex());
    }

    #[RunInSeparateProcess]
    public function test_the_section_is_content_for_publishing(): void
    {
        $this->seoOn();
        $this->migrate();
        $this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello']]);
        $form = ['title' => 'Hello', 'slug' => 'hello', '_locale' => 'en', '_seo_submitted' => '1', '_seo' => ['title' => '']];
        $this->assertSame(302, $this->post('/folio/post/p1', $form + ['_action' => 'publish'])->getStatusCode());
        $this->assertSame(PublishState::Published, $this->publisher()->state($this->stored('post', 'p1')));

        // Saving the untouched section changes nothing.
        $this->post('/folio/post/p1', $form);
        $this->assertSame(PublishState::Published, $this->publisher()->state($this->stored('post', 'p1')));

        $this->post('/folio/post/p1', ['_seo' => ['title' => 'Share me']] + $form);
        $this->assertSame(PublishState::Changed, $this->publisher()->state($this->stored('post', 'p1')), 'only _seo changed');

        $this->post('/folio/post/p1', ['_seo' => ['title' => 'Share me'], '_action' => 'publish'] + $form);
        $this->assertSame(PublishState::Published, $this->publisher()->state($this->stored('post', 'p1')));
        $snapshot = $this->dm()->findIn(\Preflow\Folio\Publishing\ShadowTypes::published($this->dm()->getTypeRegistry()->get('post')), 'p1');
        $this->assertNotNull($snapshot);
        $this->assertSame('Share me', SeoSection::fromRecord($snapshot)->title('en'), 'copied into the snapshot');
    }
}
