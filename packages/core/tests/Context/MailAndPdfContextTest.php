<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Context;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Authenticatable;
use Preflow\Auth\AuthManager;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\OutboxPolicy;
use Preflow\Core\Context\Purpose;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\SystemClock;
use Preflow\Core\Context\Viewer;
use Preflow\Core\Http\CookieQueue;
use Preflow\Core\Http\InternalRender;
use Preflow\Core\PageCache\DependencyRecorder;
use Preflow\Core\PageCache\PageCache;
use Preflow\Core\PageCache\PageCacheConfig;
use Preflow\Core\PageCache\PageKey;
use Preflow\I18n\Translator;
use Preflow\Notify\Mail\MailContent;
use Preflow\Notify\Mail\Mailer;
use Preflow\Notify\Mail\MailLayout;
use Preflow\View\AssetCollector;
use Preflow\View\NonceGenerator;
use Preflow\View\Pdf\Blocks;
use Preflow\View\Pdf\PageSettings;
use Preflow\View\Pdf\PdfDocument;
use Preflow\View\Pdf\PdfEngine;
use Preflow\View\Pdf\PdfRenderer;
use Preflow\View\TemplateEngineInterface;

/**
 * A mail's HTML part renders in its recipient's context (the reader's locale and texts, nobody
 * signed in) in a frame of its own, so nothing it collects reaches the request; a PDF renders in
 * a frame of its own derived from the caller's context (purpose Pdf, discarding), and which
 * building blocks apply is kept in that frame, not in a static.
 */
final class MailAndPdfContextTest extends TestCase
{
    private string $dir;

    private Application $app;

    protected function setUp(): void
    {
        Contexts::reset();
        $this->dir = sys_get_temp_dir() . '/mail_pdf_context_' . bin2hex(random_bytes(4));
        foreach (['config', 'lang/en', 'lang/de', 'lang/fr'] as $folder) {
            @mkdir($this->dir . '/' . $folder, 0777, true);
        }
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name' => 'mailpdf', 'debug' => 0, 'engine' => 'twig', 'key' => 'mail-pdf-context-test-key-0123456789', 'url' => 'https://site.test', 'locale' => 'en'];\n");
        file_put_contents($this->dir . '/config/i18n.php', "<?php\nreturn ['default' => 'en', 'available' => ['en', 'de', 'fr'], 'fallback' => 'en', 'url_strategy' => 'none', 'cookie' => false];\n");
        file_put_contents($this->dir . '/config/notify.php', "<?php\nreturn ['transports' => ['mail' => ['driver' => 'memory']], 'channels' => ['email' => ['transport' => 'mail', 'from' => 'site@site.test']]];\n");
        file_put_contents($this->dir . '/config/auth.php', "<?php\nreturn ['default_guard' => 'session', 'guards' => ['session' => ['class' => \\Preflow\\Auth\\SessionGuard::class, 'provider' => 'users']], 'providers' => ['users' => ['class' => \\Preflow\\Auth\\ConfigUserProvider::class, 'users' => [['id' => 'admin-1', 'email' => 'admin@site.test', 'password_hash' => '', 'roles' => []]]]], 'session' => ['cookie' => 'mailpdf', 'secure' => false]];\n");
        file_put_contents($this->dir . '/config/providers.php', "<?php\nreturn [" . var_export(HtmlPdfEngineProvider::class, true) . "];\n");
        foreach (['en' => 'Hello', 'de' => 'Hallo', 'fr' => 'Bonjour'] as $locale => $hello) {
            file_put_contents($this->dir . "/lang/{$locale}/mail.php", "<?php\nreturn ['hello' => " . var_export($hello, true) . "];\n");
        }
    }

    protected function tearDown(): void
    {
        Contexts::reset();
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    /**
     * A mail to a French reader composed on a German admin screen by a signed-in admin: the layout
     * prints the reader's locale and texts and sees nobody signed in; the admin's frame is untouched.
     */
    #[RunInSeparateProcess]
    public function test_mail_layout_renders_in_the_recipients_locale_and_anonymous(): void
    {
        file_put_contents($this->dir . '/mail.twig', "{{ current_locale() }}|{{ t('mail.hello') }}|{{ auth_check() ? 'in' : 'out' }}|{{ lang }}");
        $app = $this->app();
        $mailer = $app->container()->get(Mailer::class);
        $admin = $this->admin($app);

        [$html, $inFrame, $after] = Contexts::run($admin, function () use ($mailer): array {
            $inFrame = $this->line();
            $email = $mailer->compose(new MailContent('Salut', 'Texte', 'fr'), 'reader@site.test', template: $this->dir . '/mail.twig');

            return [(string) $email->html, $inFrame, $this->line()];
        });

        $this->assertSame('de|Hallo|in', $inFrame, 'the admin request itself reads German and is signed in');
        $this->assertSame('fr|Bonjour|out|fr', $html, 'the layout renders for the reader: French, nobody signed in');
        $this->assertSame('de|Hallo|in', $after, 'the frame around the mail is untouched');
    }

    /** CSS a mail layout collects stays in the mail; the request's assets neither reach the mail nor change. */
    #[RunInSeparateProcess]
    public function test_mail_compose_does_not_touch_the_requests_assets(): void
    {
        file_put_contents($this->dir . '/mail.twig', '{% apply css %}.mail-rule { color: red; }{% endapply %}<head>{{ head() }}</head><p>{{ subject }}</p>');
        $app = $this->app();
        $mailer = $app->container()->get(Mailer::class);
        $visit = RenderContext::fromRequest(new ServerRequest('GET', 'https://site.test/'), $app->site(), new SystemClock());

        [$html, $before, $after, $count] = Contexts::run($visit, function () use ($app, $mailer): array {
            $assets = $app->container()->get(AssetCollector::class);
            $assets->addCss('.page-rule { color: blue; }', 'page');
            $before = $assets->renderCss();
            $count = $assets->getCssCount();
            $html = (string) $mailer->compose(new MailContent('Hi', 'Text', 'en'), 'reader@site.test', template: $this->dir . '/mail.twig')->html;

            return [$html, $before, $assets->renderCss(), [$count, $assets->getCssCount()]];
        });

        $this->assertStringContainsString('.mail-rule', $html, 'the mail prints its own CSS');
        $this->assertStringNotContainsString('.page-rule', $html, 'the request\'s CSS never reaches the mail');
        $this->assertSame($before, $after, 'the request\'s assets are unchanged');
        $this->assertStringNotContainsString('.mail-rule', $after);
        $this->assertSame($count[0], $count[1]);
    }

    /** A cookie or after-response work a mail layout queues never reaches the request that composes the mail. */
    #[RunInSeparateProcess]
    public function test_what_a_mail_layout_queues_never_reaches_the_request(): void
    {
        file_put_contents($this->dir . '/config/providers.php', "<?php\nreturn [" . var_export(HtmlPdfEngineProvider::class, true) . ', ' . var_export(QueueingLayoutProvider::class, true) . "];\n");
        $app = $this->app();
        $mailer = $app->container()->get(Mailer::class);
        $visit = RenderContext::fromRequest(new ServerRequest('GET', 'https://site.test/'), $app->site(), new SystemClock());

        [$html, $cookie, $pending] = Contexts::run($visit, static function () use ($app, $mailer): array {
            $html = (string) $mailer->compose(new MailContent('Hi', 'Text', 'en'), 'reader@site.test')->html;

            return [$html, $app->container()->get(CookieQueue::class)->has('from-mail'), Contexts::frame()->outbox()->pending()];
        });

        $this->assertSame('<p>queued</p>', $html, 'the layout ran');
        $this->assertFalse($cookie, 'the layout\'s cookie is not in the request\'s queue');
        $this->assertSame([0, 0], $pending, 'nothing the layout queued is in the request\'s outbox');
    }

    /**
     * A PDF (render()) whose document renders a browser preview of another (html()) while it
     * resolves: each runs in a frame of its own under the caller's context with purpose Pdf,
     * discarding; afterwards nothing of either is left: the caller's frame renders browser blocks
     * and Blocks has no static state.
     */
    #[RunInSeparateProcess]
    public function test_pdf_render_has_its_own_frame_and_no_static_state(): void
    {
        file_put_contents($this->dir . '/outer.twig', '<p>outer</p>{{ pdf_page_break() }}');
        file_put_contents($this->dir . '/inner.twig', '<p>inner</p>{{ pdf_page_break() }}');
        file_put_contents($this->dir . '/page.twig', '{{ pdf_page_break() }}');
        $this->assertSame([], (new \ReflectionClass(Blocks::class))->getStaticProperties(), 'Blocks keeps no state in statics');

        $app = $this->app();
        $renderer = $app->container()->get(PdfRenderer::class);
        $templates = $app->container()->get(TemplateEngineInterface::class);
        $admin = $this->admin($app);
        $seen = new \ArrayObject();

        $inner = new RecordingDocument($this->dir . '/inner.twig', $seen, 'inner');
        $outer = new RecordingDocument($this->dir . '/outer.twig', $seen, 'outer', static function () use ($renderer, $inner, $seen): void {
            $seen['inner html'] = $renderer->html($inner);
        });

        [$pdf, $after] = Contexts::run($admin, function () use ($renderer, $outer, $templates): array {
            $depth = Contexts::depth();
            $pdf = $renderer->render($outer);

            return [$pdf, [Contexts::depth() - $depth, Contexts::current()->purpose->name, $templates->render($this->dir . '/page.twig', [])]];
        });

        $this->assertSame(['depth' => 2, 'purpose' => 'Pdf', 'outbox' => OutboxPolicy::Discard->name, 'locale' => 'de', 'viewer' => 'admin-1'], $seen['outer'], 'render() runs in a frame of its own, derived from the caller\'s context');
        $this->assertSame(['depth' => 3, 'purpose' => 'Pdf', 'outbox' => OutboxPolicy::Discard->name, 'locale' => 'de', 'viewer' => 'admin-1'], $seen['inner'], 'html() inside it runs in a frame of its own too');
        $this->assertStringContainsString('<pagebreak />', $pdf, 'the PDF writes mPDF markup');
        $this->assertStringNotContainsString('<pagebreak />', $seen['inner html'], 'a preview inside a PDF writes browser HTML');
        $this->assertStringContainsString('pdf-page-break', $seen['inner html']);
        $this->assertSame([0, 'Visit', '<div class="pdf-page-break" style="border-top: 1px dashed #999; margin: 2em 0;"></div>'], $after, 'the caller\'s frame is back and renders browser blocks');
    }

    /** A render in a frame of its own inside a PDF render is no PDF: its building blocks write browser HTML. */
    #[RunInSeparateProcess]
    public function test_a_page_rendered_inside_a_pdf_writes_browser_blocks(): void
    {
        file_put_contents($this->dir . '/outer.twig', '<p>outer</p>');
        file_put_contents($this->dir . '/page.twig', '{{ pdf_page_break() }}');
        $app = $this->app();
        $renderer = $app->container()->get(PdfRenderer::class);
        $templates = $app->container()->get(TemplateEngineInterface::class);
        $admin = $this->admin($app);
        $seen = new \ArrayObject();
        $page = $this->dir . '/page.twig';

        $outer = new RecordingDocument($this->dir . '/outer.twig', $seen, 'outer', static function () use ($templates, $admin, $seen, $page): void {
            $seen['in the pdf frame'] = $templates->render($page, []);
            $seen['in a page frame'] = Contexts::run($admin, static fn (): string => $templates->render($page, []));
        });

        Contexts::run($admin, static fn (): string => $renderer->render($outer));

        $this->assertSame('<pagebreak />', $seen['in the pdf frame']);
        $this->assertStringContainsString('pdf-page-break', $seen['in a page frame']);
    }

    /** A PDF preview printed into a page is part of it: what the document reads is recorded for the page. */
    #[RunInSeparateProcess]
    public function test_a_pdf_preview_in_a_page_records_its_reads_for_the_page(): void
    {
        file_put_contents($this->dir . '/cert.twig', '<p>certificate</p>');
        $app = $this->app();
        $renderer = $app->container()->get(PdfRenderer::class);
        $recorder = $app->container()->get(DependencyRecorder::class);
        $doc = new RecordingDocument($this->dir . '/cert.twig', new \ArrayObject(), 'cert', static fn () => $recorder->record('cert:42'));

        $tags = Contexts::run($this->visit($app), static function () use ($renderer, $recorder, $doc): array {
            $recording = $recorder->start();
            $recorder->record('page:1');
            $renderer->html($doc);

            return array_keys($recorder->stop($recording));
        });

        $this->assertContains('page:1', $tags);
        $this->assertContains('cert:42', $tags, 'the PDF\'s read is part of the page');
    }

    /** A mail composed while a page renders is not part of the page: what its layout reads is not recorded for it. */
    #[RunInSeparateProcess]
    public function test_a_mail_composed_in_a_page_records_nothing_for_the_page(): void
    {
        file_put_contents($this->dir . '/config/providers.php', "<?php\nreturn [" . var_export(HtmlPdfEngineProvider::class, true) . ', ' . var_export(ReadingLayoutProvider::class, true) . "];\n");
        $app = $this->app();
        $mailer = $app->container()->get(Mailer::class);
        $recorder = $app->container()->get(DependencyRecorder::class);

        [$html, $tags] = Contexts::run($this->visit($app), static function () use ($mailer, $recorder): array {
            $recording = $recorder->start();
            $recorder->record('page:1');
            $html = (string) $mailer->compose(new MailContent('Hi', 'Text', 'en'), 'reader@site.test')->html;

            return [$html, array_keys($recorder->stop($recording))];
        });

        $this->assertSame('<p>read</p>', $html, 'the layout ran');
        $this->assertContains('page:1', $tags);
        $this->assertNotContains('mail:7', $tags, 'the mail\'s read is not the page\'s');
    }

    /** A PDF preview printed into a page uses the page's CSP nonce, so the page's policy allows its styles. */
    #[RunInSeparateProcess]
    public function test_a_pdf_preview_in_a_page_uses_the_pages_nonce(): void
    {
        file_put_contents($this->dir . '/styled.twig', '{% apply css %}.cert { color: red; }{% endapply %}<head>{{ head() }}</head><p>certificate</p>');
        $app = $this->app();
        $renderer = $app->container()->get(PdfRenderer::class);
        $nonces = $app->container()->get(NonceGenerator::class);
        $doc = new RecordingDocument($this->dir . '/styled.twig', new \ArrayObject(), 'styled');

        [$page, $html] = Contexts::run($this->visit($app), static fn (): array => [$nonces->get(), $renderer->html($doc)]);

        $this->assertStringContainsString('.cert', $html);
        $this->assertStringContainsString('nonce="' . $page . '"', $html, 'the preview\'s style carries the page\'s nonce');
    }

    /** What the document refuses inside the PDF (a CSRF field, a live part) keeps the page from being stored. */
    #[RunInSeparateProcess]
    public function test_a_refusal_inside_a_pdf_reaches_the_page(): void
    {
        file_put_contents($this->dir . '/config/page_cache.php', "<?php\nreturn ['enabled' => true];\n");
        file_put_contents($this->dir . '/cert.twig', '<p>certificate</p>');
        $app = $this->app();
        $renderer = $app->container()->get(PdfRenderer::class);
        $cache = $app->container()->get(PageCache::class);
        $key = PageKey::fromRequest(new ServerRequest('GET', 'https://site.test/'), $app->container()->get(PageCacheConfig::class));
        $this->assertNotNull($key);
        $doc = new RecordingDocument($this->dir . '/cert.twig', new \ArrayObject(), 'cert', static fn () => $cache->refuse('pdf-part'));

        $refusal = Contexts::run($this->visit($app), static function () use ($renderer, $cache, $key, $doc): ?string {
            $before = $cache->begin($key);
            try {
                $renderer->html($doc);

                return $cache->refusal();
            } finally {
                $cache->end($before);
            }
        });

        $this->assertSame('pdf-part', $refusal);
    }

    /**
     * A request a page asks for in-process joins the page through the same seam (Contexts::part()):
     * its reads are the page's and it prints the page's nonce; a fragment request answers a page
     * of its own, with a nonce of its own, and its reads still count for the page.
     */
    #[RunInSeparateProcess]
    public function test_a_nested_request_still_joins_the_page(): void
    {
        file_put_contents($this->dir . '/config/middleware.php', "<?php\nreturn [" . var_export(PartRoute::class, true) . "];\n");
        $app = $this->app();
        $recorder = $app->container()->get(DependencyRecorder::class);
        $nonces = $app->container()->get(NonceGenerator::class);

        [$page, $plain, $fragment, $tags] = Contexts::run($this->visit($app), static function () use ($app, $recorder, $nonces): array {
            $recording = $recorder->start();
            $page = $nonces->get();
            $plain = (string) $app->handle(new ServerRequest('GET', 'https://site.test/part'))->getBody();
            $fragment = (string) $app->handle(new ServerRequest('GET', 'https://site.test/part', ['HX-Request' => 'true']))->getBody();

            return [$page, $plain, $fragment, array_keys($recorder->stop($recording))];
        });

        $this->assertSame($page, $plain, 'a nested request prints the page\'s nonce');
        $this->assertNotSame($page, $fragment, 'a nested fragment request answers with a nonce of its own');
        $this->assertContains('part:3', $tags, 'what a nested request reads counts for the page');
    }

    /**
     * Inside an internal render (a page cache rebuild) a nested request still joins the rebuild's
     * page, and a nested fragment request still answers a page of its own: it keeps a nonce of its
     * own, although it takes the rebuild's purpose.
     */
    #[RunInSeparateProcess]
    public function test_a_nested_fragment_request_inside_an_internal_render_keeps_its_own_nonce(): void
    {
        file_put_contents($this->dir . '/config/middleware.php', "<?php\nreturn [" . var_export(PartRoute::class, true) . "];\n");
        $app = $this->app();
        $nonces = $app->container()->get(NonceGenerator::class);
        $rebuild = RenderContext::internal(Purpose::Rebuild, $app->site(), new SystemClock(), '/');

        [$page, $plain, $fragment] = Contexts::run($rebuild, static function () use ($app, $nonces): array {
            $page = $nonces->get();
            $plain = (string) $app->handle(new ServerRequest('GET', 'https://site.test/part'))->getBody();
            $fragment = (string) $app->handle(new ServerRequest('GET', 'https://site.test/part', ['HX-Request' => 'true']))->getBody();

            return [$page, $plain, $fragment];
        });

        $this->assertSame($page, $plain, 'a nested request inside the rebuild prints the rebuild\'s nonce');
        $this->assertNotSame($page, $fragment, 'a nested fragment request inside the rebuild answers with a nonce of its own');
        $this->assertNotSame('', $fragment);
    }

    /** Inside an internal render (the SEO audit) a PDF and a mail keep its purpose: they leave no trace either. */
    #[RunInSeparateProcess]
    public function test_a_pdf_and_a_mail_inside_an_internal_render_keep_its_purpose(): void
    {
        file_put_contents($this->dir . '/cert.twig', '<p>certificate</p>');
        file_put_contents($this->dir . '/config/providers.php', "<?php\nreturn [" . var_export(HtmlPdfEngineProvider::class, true) . ', ' . var_export(PurposeLayoutProvider::class, true) . "];\n");
        $app = $this->app();
        $renderer = $app->container()->get(PdfRenderer::class);
        $mailer = $app->container()->get(Mailer::class);
        $seen = new \ArrayObject();
        $request = new ServerRequest('GET', 'https://site.test/');
        // html() and render() note what they saw under names of their own, so both are checked.
        $html = new RecordingDocument($this->dir . '/cert.twig', $seen, 'html', static function () use ($seen, $request): void {
            $seen['html internal'] = InternalRender::is($request);
        });
        $pdf = new RecordingDocument($this->dir . '/cert.twig', $seen, 'render', static function () use ($seen, $request): void {
            $seen['render internal'] = InternalRender::is($request);
        });
        $audit = RenderContext::internal(Purpose::Audit, $app->site(), new SystemClock(), '/');

        $mail = Contexts::run($audit, static function () use ($renderer, $mailer, $html, $pdf): string {
            $renderer->html($html);
            $renderer->render($pdf);

            return (string) $mailer->compose(new MailContent('Hi', 'Text', 'en'), 'reader@site.test')->html;
        });

        $this->assertSame('Audit', $seen['html']['purpose'], 'html() keeps the audit\'s purpose');
        $this->assertTrue($seen['html internal'], 'InternalRender::is() inside html()');
        $this->assertSame('Audit', $seen['render']['purpose'], 'render() keeps the audit\'s purpose');
        $this->assertTrue($seen['render internal'], 'InternalRender::is() inside render()');
        $this->assertSame('Audit|internal', $mail, 'the mail keeps the audit\'s purpose');
    }

    /** "{locale}|{t('mail.hello')}|{in or out}" of the current render, as the app's services see it. */
    private function line(): string
    {
        $ctx = Contexts::current();
        $translator = $this->app->container()->get(Translator::class);
        $auth = $this->app->container()->get(AuthManager::class);

        return $ctx->locale . '|' . $translator->get('mail.hello') . '|' . ($auth->user() !== null ? 'in' : 'out');
    }

    private function visit(Application $app): RenderContext
    {
        return RenderContext::fromRequest(new ServerRequest('GET', 'https://site.test/'), $app->site(), new SystemClock());
    }

    /** A signed-in admin on a German admin screen. */
    private function admin(Application $app): RenderContext
    {
        return RenderContext::fromRequest(new ServerRequest('GET', 'https://site.test/admin'), $app->site(), new SystemClock())
            ->in('de')
            ->admin('de')
            ->as(new Viewer(['session' => new class implements Authenticatable {
                public function getAuthId(): string
                {
                    return 'admin-1';
                }

                public function getPasswordHash(): string
                {
                    return '';
                }

                public function getRoles(): array
                {
                    return ['admin'];
                }

                public function hasRole(string $role): bool
                {
                    return $role === 'admin';
                }
            }]));
    }

    private function app(): Application
    {
        $app = Application::create($this->dir);
        $app->boot();
        $this->app = $app;

        return $app;
    }
}

/** A PDF document that notes the context it resolves in, and runs $during while it does. */
final class RecordingDocument extends PdfDocument
{
    /** @param \ArrayObject<string, mixed> $seen */
    public function __construct(
        private readonly string $file,
        private readonly \ArrayObject $seen,
        private readonly string $name,
        private readonly ?\Closure $during = null,
    ) {}

    public function template(): string
    {
        return $this->file;
    }

    public function resolveState(): void
    {
        $ctx = Contexts::current();
        $this->seen[$this->name] = [
            'depth' => Contexts::depth(),
            'purpose' => $ctx->purpose->name,
            'outbox' => $ctx->outbox->name,
            'locale' => $ctx->locale,
            'viewer' => $ctx->viewer->primary()?->getAuthId() ?? 'anonymous',
        ];
        if ($this->during !== null) {
            ($this->during)();
        }
    }
}

/** A PDF engine that returns the page's HTML, so a test can read what the blocks wrote. */
final class HtmlPdfEngineProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        $container->singleton(PdfEngine::class, static fn (): PdfEngine => new class implements PdfEngine {
            public function render(string $html, PageSettings $page, string $headerHtml, string $footerHtml): string
            {
                return 'pdf:' . $html;
            }
        });
    }
}

/** Binds a mail layout that queues a cookie and after-response work while it renders. */
final class QueueingLayoutProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        $container->singleton(MailLayout::class, static fn (Container $c): MailLayout => new class ($c) implements MailLayout {
            public function __construct(private readonly Container $c) {}

            public function html(MailContent $content, string $accent, ?string $template = null): string
            {
                $this->c->get(CookieQueue::class)->queue('from-mail=1; Path=/');
                Contexts::frame()->outbox()->afterResponse(static function (): void {});

                return '<p>queued</p>';
            }
        });
    }
}

/** Binds a mail layout that reads a tagged record while it renders. */
final class ReadingLayoutProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        $container->singleton(MailLayout::class, static fn (Container $c): MailLayout => new class ($c) implements MailLayout {
            public function __construct(private readonly Container $c) {}

            public function html(MailContent $content, string $accent, ?string $template = null): string
            {
                $this->c->get(DependencyRecorder::class)->record('mail:7');

                return '<p>read</p>';
            }
        });
    }
}

/** Binds a mail layout that prints the purpose it renders under and whether it is internal. */
final class PurposeLayoutProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        $container->singleton(MailLayout::class, static fn (): MailLayout => new class implements MailLayout {
            public function html(MailContent $content, string $accent, ?string $template = null): string
            {
                return Contexts::current()->purpose->name . '|' . (InternalRender::is(new \Nyholm\Psr7\ServerRequest('GET', '/')) ? 'internal' : 'visitor');
            }
        });
    }
}

/** Answers /part with the nonce it renders with, after reading a tagged record. */
final class PartRoute implements \Psr\Http\Server\MiddlewareInterface
{
    public function __construct(private readonly Container $container) {}

    public function process(\Psr\Http\Message\ServerRequestInterface $request, \Psr\Http\Server\RequestHandlerInterface $handler): \Psr\Http\Message\ResponseInterface
    {
        if ($request->getUri()->getPath() !== '/part') {
            return $handler->handle($request);
        }
        $this->container->get(DependencyRecorder::class)->record('part:3');

        return new \Nyholm\Psr7\Response(200, [], $this->container->get(NonceGenerator::class)->get());
    }
}
