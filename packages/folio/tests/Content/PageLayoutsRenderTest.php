<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Content;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Content\FrontendRoute;
use Preflow\Folio\Storage\TableSync;
use Preflow\Folio\Tests\Support\PageLayoutsScaffold;
use Preflow\Testing\ChecksFrameBalance;

/**
 * Page layouts on the frontend and in the live preview (0.92): the template chain (route template,
 * the type's @folio template, the layout's template, the generic page), the "layout" and "areas"
 * variables, and a type without "layouts" exactly as in 0.91.
 */
final class PageLayoutsRenderTest extends TestCase
{
    use ChecksFrameBalance;

    use PageLayoutsScaffold;

    protected function setUp(): void
    {
        $this->scaffoldLayouts();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    #[RunInSeparateProcess]
    public function test_type_without_layouts_is_unchanged(): void
    {
        $this->write('resources/folio/frontend/post.twig', '<html><body><p class="keys">{{ _context|keys|join(",") }}</p></body></html>');
        $this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello']]);
        $app = $this->app();
        $def = $app->container()->get(\Preflow\Data\DataManager::class)->getTypeRegistry()->get('post');

        $this->assertSame(TableSync::columns(), $app->container()->get(TableSync::class)->columnsFor($def), 'no new columns');
        $this->assertSame(TableSync::columns(), (new \Preflow\Folio\Publishing\FolioRecordColumns())->columns($def));
        $this->assertNull($app->container()->get(\Preflow\Folio\Content\RecordRenderer::class)->layoutFor(\Preflow\Data\DynamicRecord::fromArray($def, ['uuid' => 'p1'])));

        $this->publisher()->publish($this->stored('post', 'p1'), null);
        $page = (string) $this->get('/hello')->getBody();
        $this->assertStringContainsString('<p class="keys">record,rendered,type,preview,locale,alternates,query', $page, 'the 0.91 template variables, no "layout" or "areas"');
        $this->assertStringNotContainsString('layout', substr($page, (int) strpos($page, 'class="keys"')));

        $preview = $this->previewOf('post', 'p1', ['title' => 'Hello', 'slug' => 'hello']);
        $this->assertStringContainsString('<p class="keys">record,rendered,type,preview,locale,alternates', $preview);
        $this->assertStringNotContainsString(',layout', $preview);

        $form = (string) $this->get('/folio/post/p1/edit')->getBody();
        $this->assertStringContainsString('name="title"', $form);
        foreach (['_layout', '_areas'] as $name) {
            $this->assertStringNotContainsString($name, $form, 'no new form controls');
        }

        $this->assertSame(['templates/post.twig', '@folio/frontend/post.twig', '@folio/frontend/page.twig'], FrontendRoute::fromConfig('post', ['route' => '/{slug}', 'template' => 'templates/post.twig'])->templates());
    }

    #[RunInSeparateProcess]
    public function test_frontend_renders_the_layout_template_with_areas(): void
    {
        $this->page('r1', 'about', 'landing', [
            'hero' => [self::hero(self::HERO, 'Welcome')],
            'main' => [self::prose(self::PROSE, ['en' => 'First'])],
            'sidebar' => [self::prose(self::OTHER, ['en' => 'Kept but not shown'])],
        ]);

        $page = (string) $this->get('/p/about')->getBody();

        $this->assertStringContainsString('<main class="landing" data-layout="landing">', $page);
        $this->assertMatchesRegularExpression('#<div class="hero"><div id="HeroBlock-[^"]+"><section class="hero"><h1>Welcome</h1></section>\s*</div></div>#', $page);
        $this->assertStringContainsString('<p class="caption">First</p>', $page);
        $this->assertStringNotContainsString('Kept but not shown', $page, 'an area the layout lacks never renders');
    }

    #[RunInSeparateProcess]
    public function test_type_template_wins_and_may_include_the_layout_template(): void
    {
        $this->write('resources/folio/frontend/page.twig', '<p>generic</p>');
        $this->page('r1', 'about', 'standard', ['main' => [self::prose(self::PROSE, ['en' => 'Body text'])]]);
        $this->assertStringContainsString('<main class="standard"', (string) $this->get('/p/about')->getBody(), 'the layout template comes before the generic page template (for a type named "page" too)');
        $this->assertSame(
            ['templates/page.twig', '@folio/frontend/post.twig', 'layouts/standard.twig', '@folio/frontend/page.twig'],
            FrontendRoute::fromConfig('post', ['route' => '/{slug}', 'template' => 'templates/page.twig'])->templates('layouts/standard.twig'),
        );
        $this->assertSame(['layouts/standard.twig', '@folio/frontend/page.twig'], FrontendRoute::fromConfig('page', ['route' => '/{slug}'])->templates('layouts/standard.twig'));
        $this->assertSame(['@folio/frontend/page.twig'], FrontendRoute::fromConfig('page', ['route' => '/{slug}'])->templates(), 'without a layout as in 0.91');

        $model = json_decode((string) file_get_contents($this->dir . '/config/models/page.json'), true);
        $model['frontend']['template'] = 'templates/page-type.twig';
        $this->write('config/models/page.json', (string) json_encode($model));
        $this->write('templates/page-type.twig', '<div class="own">{{ record.title }} on {{ layout.label }}</div>{% include layout.template %}');

        $page = (string) $this->get('/p/about')->getBody();
        $this->assertStringContainsString('<div class="own">About on Standard</div>', $page, 'the type\'s own template wins');
        $this->assertStringContainsString('<main class="standard" data-layout="standard"><div class="main">', $page, 'and includes the layout\'s template');
        $this->assertStringContainsString('<p class="caption">Body text</p>', $page);
    }

    #[RunInSeparateProcess]
    public function test_page_on_a_removed_layout_renders_with_the_default(): void
    {
        $this->page('r1', 'about', 'gone', [
            'hero' => [self::hero(self::HERO, 'Hidden hero')],
            'main' => [self::prose(self::PROSE, ['en' => 'Still here'])],
        ]);

        $response = $this->get('/p/about');
        $page = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode(), 'the site keeps working');
        $this->assertStringContainsString('<main class="standard" data-layout="standard">', $page, 'the type\'s default');
        $this->assertStringContainsString('<p class="caption">Still here</p>', $page);
        $this->assertStringNotContainsString('Hidden hero', $page, 'the area the default lacks is kept, not rendered');
        $this->assertSame('gone', $this->stored('page', 'r1')->get('_layout'), 'nothing is rewritten');
    }

    #[RunInSeparateProcess]
    public function test_pinned_page_renders_its_pin_whatever_is_stored(): void
    {
        $this->page('r1', 'home', 'landing', [
            'top' => [self::prose(self::PROSE, ['en' => 'Top block'])],
            'hero' => [self::hero(self::HERO, 'Landing hero')],
        ]);

        $page = (string) $this->get('/p/home')->getBody();

        $this->assertStringContainsString('<main class="home" data-layout="home" data-pinned="1">', $page);
        $this->assertStringContainsString('<p class="caption">Top block</p>', $page);
        $this->assertStringNotContainsString('Landing hero', $page);
    }

    #[RunInSeparateProcess]
    public function test_live_preview_marks_area_blocks(): void
    {
        $this->page('r1', 'about', 'landing', ['hero' => [self::hero(self::HERO, 'Stored hero')], 'main' => []]);

        $html = $this->previewOf('page', 'r1', [
            'title' => 'About', 'slug' => 'about', '_layout' => 'landing', '_areas_submitted' => '1',
            '_areas' => ['main' => ['_order' => [self::PROSE], self::PROSE => ['_block' => 'prose', 'caption' => 'Typed, not saved', 'body' => '']]],
        ]);

        $this->assertStringContainsString('<main class="landing" data-layout="landing">', $html);
        $this->assertMatchesRegularExpression('#<div id="ProseBlock-[^"]+" data-folio-block="' . self::PROSE . '" data-folio-label="Prose"><section class="prose">#', $html, 'the posted area block, marked');
        $this->assertMatchesRegularExpression('#<div id="HeroBlock-[^"]+" data-folio-block="' . self::HERO . '" data-folio-label="Hero"><section class="hero"><h1>Stored hero</h1>#', $html, 'the area the form did not post: stored');
        $this->assertStringContainsString('Typed, not saved', $html);
        $this->assertSame([], $this->stored('page', 'r1')->get('_areas')['main'] ?? null, 'the preview stores nothing');

        $switched = $this->previewOf('page', 'r1', ['title' => 'About', 'slug' => 'about', '_layout' => 'standard', '_areas_submitted' => '1']);
        $this->assertStringContainsString('<main class="standard" data-layout="standard">', $switched, 'the layout chosen in the form');
        $this->assertStringNotContainsString('Stored hero', $switched);
    }

    /** @param array<string, mixed> $body */
    private function previewOf(string $type, string $id, array $body): string
    {
        $request = (new Psr17Factory())->createServerRequest('POST', '/folio/' . $type . '/' . $id . '/preview')
            ->withHeader('Sec-Fetch-Site', 'same-origin')
            ->withParsedBody($body + ['_locale' => 'en']);
        $response = $this->app()->handle($request);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return (string) $response->getBody();
    }
}
