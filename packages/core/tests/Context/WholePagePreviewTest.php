<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Context;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\Purpose;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\RenderResult;
use Preflow\Core\Context\SystemClock;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Notify\Email;
use Preflow\Notify\Notifier;
use Preflow\Notify\TransportFactory;
use Preflow\Publishing\LiveContent;
use Preflow\Publishing\Publisher;
use Preflow\Testing\ChecksFrameBalance;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;

/**
 * The whole-page preview without Folio: an app with preflow/publishing previews a page as a
 * visitor will see it once its working copies are published, with core calls only
 * (Application::preview(), inside the request of an editor screen of its own):
 *
 *     $result = $app->preview(Contexts::current(), '/de/about', 'de');
 *     $result->response;            // the page, its layout, navigation and SEO title from the working copies
 *     $result->wouldSend('notify'); // the mail it would send (captured, never sent)
 *     $result->wouldWrite();        // the data writes it would make (skipped)
 *
 * The site: pages "about" and "team" in en and de, published, then edited (working copies
 * "Entwurf" and "Leute"); the page template extends a layout with a navigation of every page's
 * title, sets its SEO title and prints an inline script with the render's nonce. Each GET counts
 * a visit (a data write) and queues a mail.
 */
final class WholePagePreviewTest extends TestCase
{
    use ChecksFrameBalance;

    private string $dir;

    protected function setUp(): void
    {
        Contexts::reset();
        $this->dir = sys_get_temp_dir() . '/whole_page_preview_' . bin2hex(random_bytes(4));
        ini_set('error_log', $this->dir . '/php.log');
    }

    protected function tearDown(): void
    {
        Contexts::reset();
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    #[RunInSeparateProcess]
    public function test_whole_page_preview_renders_layout_nav_and_seo_title_from_working_copies(): void
    {
        $app = $this->site();
        $published = (string) $app->handle(new ServerRequest('GET', 'https://site.test/de/about'))->getBody();
        $this->assertStringContainsString('<h1>Über uns</h1>', $published, 'a visitor sees the published page');

        $result = $this->fromAnEditorScreen($app, static fn (): RenderResult => $app->preview(Contexts::current(), '/de/about', 'de'));

        $body = (string) $result->response->getBody();
        $this->assertSame(200, $result->response->getStatusCode());
        $this->assertStringContainsString('<header class="layout">', $body, 'the layout');
        $this->assertStringContainsString('<nav>Entwurf · Leute</nav>', $body, 'the navigation reads the working copies');
        $this->assertStringContainsString('<h1>Entwurf</h1>', $body);
        $this->assertStringContainsString('<title>Entwurf</title>', $body, 'the SEO title');
        $this->assertStringContainsString('<meta property="og:title" content="Entwurf">', $body, 'and the share card');
        $this->assertStringContainsString('<p class="who">de|out|public|working|preview</p>', $body, 'an anonymous visitor in the locale asked for, reading working copies');

        // The response is the render's own: the nonce its inline script carries is the one in its policy.
        $this->assertSame(1, preg_match('/<script nonce="([^"]+)">/', $body, $script), $body);
        $this->assertStringContainsString("'nonce-{$script[1]}'", $result->response->getHeaderLine('Content-Security-Policy'));

        $this->assertStringContainsString('<h1>Über uns</h1>', (string) $app->handle(new ServerRequest('GET', 'https://site.test/de/about'))->getBody(), 'the next visitor still sees the published page');
    }

    #[RunInSeparateProcess]
    public function test_captured_mail_is_reported_not_sent(): void
    {
        $app = $this->site();
        $visits = static fn (): int => count($app->container()->get(DataManager::class)->queryType('visit')->get()->items());
        $before = $visits();

        $result = $this->fromAnEditorScreen($app, static fn (): RenderResult => $app->preview(Contexts::current(), '/about', 'en'));

        $this->assertStringContainsString('<h1>Draft</h1>', (string) $result->response->getBody());
        $this->assertSame(1, $result->wouldSend('notify'), 'the page would send one mail');
        $this->assertSame(1, $result->wouldWrite(), 'and write one record');
        $this->assertSame('visit', $result->skippedWrites[0]->type);
        $this->assertSame($before, $visits(), 'nothing was written');
        $this->assertSame(0, $app->container()->get(Notifier::class)->flushDeferred(), 'nothing is left in notify\'s queue');
        $this->assertSame([], $app->container()->get(TransportFactory::class)->get('mail')->sent(), 'nothing was sent, also not when the editor\'s request ended');

        // Proof: a visitor's request of the page sends its mail and counts the visit.
        $app->handle(new ServerRequest('GET', 'https://site.test/about'));
        $this->assertCount(1, $app->container()->get(TransportFactory::class)->get('mail')->sent());
        $this->assertSame($before + 1, $visits());
    }

    /** The preview keeps nothing of the request it is asked from: no cookie, session, header or locale prefix. */
    #[RunInSeparateProcess]
    public function test_the_preview_takes_nothing_from_the_callers_request(): void
    {
        $app = $this->site();
        $editor = (new ServerRequest('GET', 'https://site.test/de/editor', ['X-Marker' => 'editor']))->withCookieParams(['m' => 'editor-cookie']);

        $body = (string) Contexts::run(
            RenderContext::fromRequest($editor, $app->site(), new SystemClock())->prefixed('/de'),
            static fn (): RenderResult => $app->preview(Contexts::current(), '/about', 'en'),
        )->response->getBody();

        $this->assertStringContainsString('<p class="cookie">|</p>', $body, 'no cookie, no header of the editor\'s request');
        $this->assertStringContainsString('<p class="who">en|out|public|working|preview</p>', $body);
        $this->assertSame(0, Contexts::depth());
    }

    /**
     * The preview derives from the caller's context: its facets carry over (a tenant, a variant),
     * but never a preview token's grant, which grants the caller's reading mode, not the preview's.
     */
    #[RunInSeparateProcess]
    public function test_the_preview_keeps_the_callers_facets_but_never_a_preview_grant(): void
    {
        $app = $this->site();
        $request = new ServerRequest('GET', 'https://site.test/editor');
        $from = RenderContext::fromRequest($request, $app->site(), new SystemClock())->admin('en')
            ->with(new WholePagePreviewFacet('tenant-a'))
            ->with(new \Preflow\Core\Context\Preview\PreviewGrant('v1.callers-token.x.y'));

        $body = (string) Contexts::run($from, static fn (): RenderResult => $app->preview(Contexts::current(), '/about', 'en'))->response->getBody();

        $this->assertStringContainsString('<p class="facets">tenant-a|no-grant</p>', $body);
        $this->assertStringNotContainsString('callers-token', $body);
        $this->assertSame(0, Contexts::depth());
    }

    #[RunInSeparateProcess]
    public function test_a_preview_path_is_a_clean_path(): void
    {
        $app = $this->site();

        $this->expectException(\InvalidArgumentException::class);
        Contexts::run(RenderContext::console($app->site(), new SystemClock()), static fn (): RenderResult => $app->preview(Contexts::current(), '/about?x=1', 'en'));
    }

    /**
     * $preview called from a screen of the app's own: inside the request frame of a signed-in
     * editor's request, which delivers (as a request does). Its response has gone out by the end.
     *
     * @param \Closure(): RenderResult $preview
     */
    private function fromAnEditorScreen(Application $app, \Closure $preview): RenderResult
    {
        $request = new ServerRequest('GET', 'https://site.test/editor');
        $result = Contexts::run(RenderContext::fromRequest($request, $app->site(), new SystemClock())->admin('en'), $preview);
        $this->assertSame(0, Contexts::depth());

        return $result;
    }

    private function site(): Application
    {
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Preview', 'debug' => 0, 'engine' => 'twig', 'key' => 'whole-page-preview-test-key-0123456789', 'url' => 'https://site.test', 'locale' => 'en'];\n");
        $this->write('config/i18n.php', "<?php\nreturn ['default' => 'en', 'available' => ['en', 'de'], 'fallback' => 'en', 'url_strategy' => 'prefix'];\n");
        $this->write('config/seo.php', "<?php\nreturn ['enabled' => true];\n");
        $this->write('config/notify.php', "<?php\nreturn ['transports' => ['mail' => ['driver' => 'memory']], 'channels' => ['email' => ['transport' => 'mail', 'from' => 'site@site.test']]];\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Publishing\\PublishingServiceProvider::class, " . var_export(WholePagePreviewProvider::class, true) . "];\n");
        $this->write('config/data.php', <<<'PHP'
            <?php
            return [
                'drivers' => ['json' => ['driver' => \Preflow\Data\Driver\JsonFileDriver::class, 'path' => __DIR__ . '/../storage/data']],
                'default' => 'json',
                'models_path' => __DIR__ . '/models',
            ];
            PHP);
        $this->write('config/models/page.json', (string) json_encode(['key' => 'page', 'table' => 'page', 'storage' => 'json', 'publishing' => true, 'fields' => [
            'title' => ['type' => 'string', 'translatable' => true],
            'position' => ['type' => 'integer'],
        ]]));
        $this->write('config/models/visit.json', (string) json_encode(['key' => 'visit', 'table' => 'visit', 'storage' => 'json', 'fields' => ['path' => ['type' => 'string']]]));
        $this->write('app/pages/_layout.twig', '<html><head><title>{% block title %}{% endblock %}</title></head><body><header class="layout"><nav>{{ nav_titles()|join(" · ") }}</nav></header>{% block main %}{% endblock %}</body></html>');
        $this->write('app/pages/about.twig', "{% extends '_layout.twig' %}{% block title %}{{ page_title('about') }}{% endblock %}{% block main %}{{ seo({'title': page_title('about')}) }}{{ visit_with_side_effects() }}"
            . '<main><h1>{{ page_title("about") }}</h1><p class="who">{{ current_locale() }}|{{ preview_probe() }}</p>'
            . '<p class="cookie">{{ request_probe() }}</p><p class="facets">{{ facets_probe() }}</p><script nonce="{{ csp_nonce() }}">window.previewed = true;</script></main>{% endblock %}');
        $this->write('app/pages/editor.twig', '<p>editor</p>');

        $app = Application::create($this->dir);
        $app->boot();

        // Published, then edited: the working copies differ from what visitors see.
        $dm = $app->container()->get(DataManager::class);
        $type = $dm->getTypeRegistry()->get('page');
        $publisher = $app->container()->get(Publisher::class);
        foreach (['about' => [['en' => 'About', 'de' => 'Über uns'], 1], 'team' => [['en' => 'Team', 'de' => 'Team'], 2]] as $id => [$title, $position]) {
            $record = DynamicRecord::fromArray($type, ['uuid' => $id, 'title' => $title, 'position' => $position, '_locales' => ['en', 'de']]);
            $dm->saveType($record, false);
            $publisher->publish($record, null);
        }
        $dm->saveType(DynamicRecord::fromArray($type, ['uuid' => 'about', 'title' => ['en' => 'Draft', 'de' => 'Entwurf'], 'position' => 1, '_locales' => ['en', 'de']]), false);
        $dm->saveType(DynamicRecord::fromArray($type, ['uuid' => 'team', 'title' => ['en' => 'People', 'de' => 'Leute'], 'position' => 2, '_locales' => ['en', 'de']]), false);

        return $app;
    }

    private function write(string $rel, string $content): void
    {
        @mkdir(dirname($this->dir . '/' . $rel), 0777, true);
        file_put_contents($this->dir . '/' . $rel, $content);
    }
}

/**
 * Site code of the test app: its page reads content through publishing's LiveContent, as the
 * render's read mode says; each visit is counted and announced by mail.
 */
final class WholePagePreviewProvider extends ServiceProvider
{
    public function register(Container $container): void {}

    public function boot(Container $container): void
    {
        $engine = $container->get(TemplateEngineInterface::class);
        $live = static fn (): LiveContent => $container->get(LiveContent::class);
        $engine->addFunction(new TemplateFunctionDefinition(
            name: 'page_title',
            callable: static fn (string $id): string => (string) ($live()->find('page', $id)?->localized(Contexts::current()->locale)->get('title') ?? ''),
        ));
        $engine->addFunction(new TemplateFunctionDefinition(
            name: 'nav_titles',
            callable: static fn (): array => array_map(
                static fn (DynamicRecord $page): string => (string) $page->localized(Contexts::current()->locale)->get('title'),
                $live()->query('page')->orderBy('position')->get()->items(),
            ),
        ));
        $engine->addFunction(new TemplateFunctionDefinition(
            name: 'preview_probe',
            callable: static fn (): string => implode('|', [
                Contexts::current()->viewer->isAnonymous() ? 'out' : 'in',
                Contexts::current()->surface->admin ? 'admin' : 'public',
                $live()->readsWorkingCopies('page') ? 'working' : 'published',
                Contexts::current()->purpose->value,
            ]),
        ));
        $engine->addFunction(new TemplateFunctionDefinition(
            name: 'facets_probe',
            callable: static function (): string {
                $ctx = Contexts::current();
                $grant = $ctx->get(\Preflow\Core\Context\Preview\PreviewGrant::class);

                return ($ctx->get(WholePagePreviewFacet::class)?->memoKey() ?? '-') . '|' . ($grant === null ? 'no-grant' : 'grant:' . $grant->token());
            },
        ));
        $engine->addFunction(new TemplateFunctionDefinition(
            name: 'request_probe',
            callable: static function (): string {
                $request = Contexts::current()->request?->request;

                return ($request?->getCookieParams()['m'] ?? '') . '|' . ($request?->getHeaderLine('X-Marker') ?? '');
            },
        ));
        $engine->addFunction(new TemplateFunctionDefinition(
            name: 'visit_with_side_effects',
            callable: static function () use ($container): string {
                $dm = $container->get(DataManager::class);
                $dm->saveType(DynamicRecord::fromArray($dm->getTypeRegistry()->get('visit'), ['uuid' => bin2hex(random_bytes(4)), 'path' => '/about']), false);
                $container->get(Notifier::class)->sendLater(new Email('owner@site.test', 'Visited', 'Someone read the page.'));

                return '';
            },
        ));
    }
}

/** A facet of the caller's (a tenant): it carries over into the preview. */
final readonly class WholePagePreviewFacet implements \Preflow\Core\Context\Facet
{
    public function __construct(public string $tenant) {}

    public function isPublicDefault(): bool
    {
        return true;
    }

    public function memoKey(): string
    {
        return $this->tenant;
    }
}
