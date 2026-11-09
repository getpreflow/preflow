<?php

declare(strict_types=1);

namespace Tests\Site;

use App\Seeds\ContentSeeder;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Folio\Publishing\Publisher;
use Preflow\Media\Image\ImageBackends;
use Tests\Support\StarterApp;

/**
 * The home page and the news are Folio content: staff build pages from blocks, edit them with a
 * live preview, and publish when ready. SEO and GEO describe them to search engines and AI tools.
 */
final class PageFirstTest extends TestCase
{
    use StarterApp;

    private const string HOME = ContentSeeder::HOME;

    protected function setUp(): void
    {
        $this->bootStarter();
    }

    protected function tearDown(): void
    {
        $this->removeStarter();
    }

    private function data(): DataManager
    {
        return $this->container()->get(DataManager::class);
    }

    private function publisher(): Publisher
    {
        return $this->container()->get(Publisher::class);
    }

    /** @return list<string> the paths of the links to $prefix/<slug> */
    private function links(string $html, string $prefix): array
    {
        preg_match_all('#href="(' . preg_quote($prefix, '#') . '/[a-z0-9-]+)"#', $html, $matches);

        return array_values(array_unique($matches[1]));
    }

    /** @return array<string, mixed> the home page's first block of the kind */
    private function homeBlock(string $kind): array
    {
        $sections = $this->data()->findType('page', self::HOME)?->get('sections');
        $sections = is_string($sections) ? json_decode($sections, true) : $sections;
        foreach ((array) $sections as $block) {
            if (($block['_block'] ?? null) === $kind) {
                return $block;
            }
        }
        $this->fail('The home page has no ' . $kind . ' block');
    }

    public function test_home_is_a_folio_page_with_blocks(): void
    {
        $this->assertSame(md5('starter-page-1'), self::HOME);
        $this->assertFileDoesNotExist($this->starterRoot . '/app/pages/index.twig', 'Folio serves /, not a file page');
        $this->assertNotNull($this->publisher()->snapshot('page', self::HOME), 'the home page is published');

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('<h1>Preflow Jobs</h1>'); // the hero's headline
        $html = $response->body();
        $this->assertCount(6, $this->links($html, '/jobs'), 'latest_jobs: six job cards');
        $this->assertCount(3, $this->links($html, '/news'), 'latest_news: three news teasers');
        $response->assertSee('class="hero__cta" href="/jobs"'); // a site path still gets its button
        $response->assertSee('href="/employer"'); // the text block
        // The layout prints the page's description once; SEO adds the share cards beside it.
        $this->assertSame(1, substr_count($html, '<meta name="description"'));
        $response->assertSee('<meta name="description" content="A small job board built with Preflow');
        $response->assertSee('property="og:title"');
    }

    public function test_news_list_and_detail(): void
    {
        $list = $this->get('/news');

        $list->assertOk();
        $links = $this->links($list->body(), '/news');
        $this->assertCount(3, $links);
        // Newest first.
        $this->assertSame('/news/forty-jobs-and-counting', $links[0]);
        $list->assertSee('<meta name="description"');

        $detail = $this->get($links[0]);
        $detail->assertOk();
        $detail->assertSee('<h1>Forty jobs and counting</h1>');
        $detail->assertSee('datetime="2026-11-05"');
        if (ImageBackends::detect() !== null && ($_ENV['STARTER_NO_IMAGES'] ?? getenv('STARTER_NO_IMAGES')) !== '1') {
            $detail->assertSee('<img'); // the news image: the seed imports it only with an image library
        }
        $this->assertSame(1, substr_count($detail->body(), '<meta name="description"'));
        $detail->assertSee('property="og:type" content="article"');

        $this->get('/news/no-such-post')->assertStatus(404);
    }

    public function test_preview_marks_blocks_for_click_to_edit(): void
    {
        $cookies = ['Cookie' => $this->cookieHeader($this->signInStaff())];
        $form = $this->get('/folio/page/' . self::HOME . '/edit', $cookies);
        $form->assertOk();
        preg_match('/name="_csrf_token" value="([^"]+)"/', $form->body(), $match);
        $uids = array_map(fn (string $kind): string => $this->homeBlock($kind)['_uid'], ['hero', 'text', 'latest_jobs', 'latest_news']);

        // The editor posts the form as it stands; blocks listed without their fields keep what is saved.
        $response = $this->post('/folio/page/' . self::HOME . '/preview', [
            'title' => 'Home',
            'slug' => 'home',
            'sections' => ['_order' => $uids],
            '_locale' => 'en',
            '_csrf_token' => html_entity_decode($match[1] ?? ''),
        ], $cookies);

        $response->assertOk();
        $response->assertSee('data-folio-block="' . $uids[0] . '"');
        $response->assertSee('data-folio-edit="headline"');
    }

    public function test_draft_page_change_not_public_until_published(): void
    {
        $record = $this->data()->findType('page', self::HOME);
        $sections = $record->get('sections');
        $sections = is_string($sections) ? json_decode($sections, true) : $sections;
        foreach ($sections as $i => $block) {
            if ($block['_block'] === 'hero') {
                $sections[$i]['headline'] = 'Hiring this winter';
                $sections[$i]['cta_url'] = '//evil.example'; // looks like a path, but a browser reads it as another site
            }
        }
        $record->set('sections', $sections);
        $this->publisher()->saveDraft($record, null);

        $this->get('/')->assertSee('<h1>Preflow Jobs</h1>')->assertNotSee('Hiring this winter');

        $this->publisher()->publish($this->data()->findType('page', self::HOME), 'test');

        $this->get('/')->assertSee('<h1>Hiring this winter</h1>')->assertNotSee('evil.example');
    }

    public function test_hero_button_refuses_links_that_leave_the_site(): void
    {
        foreach (['//evil.example', '/\\evil.example', 'javascript:alert(1)'] as $url) {
            $record = $this->data()->findType('page', self::HOME);
            $sections = $record->get('sections');
            $sections = is_string($sections) ? json_decode($sections, true) : $sections;
            foreach ($sections as $i => $block) {
                if ($block['_block'] === 'hero') {
                    $sections[$i]['cta_url'] = $url;
                }
            }
            $record->set('sections', $sections);
            $this->publisher()->saveDraft($record, null);
            $this->publisher()->publish($this->data()->findType('page', self::HOME), 'test');

            $this->get('/')->assertOk()->assertNotSee('href="' . $url); // the debug toolbar prints props, so look for the link itself
        }
    }

    public function test_seo_section_on_page_form(): void
    {
        $cookies = ['Cookie' => $this->cookieHeader($this->signInStaff())];

        foreach (['/folio/page/' . self::HOME . '/edit', '/folio/news/' . md5('starter-news-1') . '/edit'] as $path) {
            $form = $this->get($path, $cookies);
            $form->assertOk();
            $form->assertSee('SEO & sharing');
            $form->assertSee('name="_seo[noindex]"');
        }
    }

    public function test_llms_txt_lists_pages(): void
    {
        $llms = $this->get('/llms.txt');

        $llms->assertOk();
        $llms->assertSee('/index.md');
        $llms->assertSee('/news/forty-jobs-and-counting.md');

        $markdown = $this->get('/index.md');
        $markdown->assertOk();
        $markdown->assertSee('Preflow Jobs');
    }

    public function test_error_boundary_page(): void
    {
        $this->get('/')->assertSee('href="/about-error-boundaries"'); // in the footer

        $response = $this->get('/about-error-boundaries');

        $response->assertOk();
        $response->assertSee('Error Boundary: this is a preview');
        $response->assertSee('<meta name="description"');
    }
}
