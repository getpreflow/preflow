<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Context;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Preflow\Core\Application;
use Preflow\Core\Context\CapturedItem;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\Purpose;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\RenderFrame;
use Preflow\Core\Context\RenderResult;
use Preflow\Core\Context\Site;
use Preflow\Folio\Tests\Fixtures\Isolation\IsolationDocument;
use Preflow\Folio\Tests\Fixtures\Isolation\IsolationProbeProvider;
use Preflow\Folio\Tests\Fixtures\Isolation\IsolationSideEffects;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\Notify\Mail\MailContent;
use Preflow\Notify\Mail\Mailer;
use Preflow\Testing\ContextFactory;
use Preflow\Testing\Contract\RenderIsolationContract;
use Preflow\View\Pdf\PdfRenderer;

/**
 * The isolation contract over a Folio site, all seven internal render paths: a published post
 * "Hello" (de "Hallo") with a working copy "Draft" (de "Entwurf"), served by Folio's frontend at
 * /hello in both locales. Its page prints the record's title (Folio's frontend and
 * folio_find(), so the read mode counts), the SEO title and the assets it rendered with, and
 * "{locale}|{in or out}|{the request's cookie m}|{render time}|{an overridable text}"; every GET
 * queues a cookie and a mail. Mail and PDF render the same line from a template of their own.
 *
 * Each path renders the way its caller does in 0.81: audit, GEO and rebuild as internal renders
 * of /hello through Application::render(), verify as the visitor's context discarding and
 * skipping writes, preview through Application::preview() (the entry Folio's whole-page preview
 * calls) in the locale it is given (set explicitly, never the admin frame's); mail through the
 * Mailer and PDF through PdfRenderer, each called in the caller's frame. Folio keeps no ambient
 * state of its own (it only builds contexts), so poisonApp() has nothing to add to the contract's
 * poison.
 */
#[RunTestsInSeparateProcesses]
final class FolioRenderIsolationTest extends RenderIsolationContract
{
    use PublishingScaffold;

    private const string LINE = '{{ seo_title() }}|{{ head() }}{{ assets() }}|{{ current_locale() }}|{{ auth_check() ? "in" : "out" }}|{{ request_cookie("m") }}|{{ render_time() }}|{{ t("isolation.hello") }}';

    protected function setUp(): void
    {
        parent::setUp();
        $this->scaffoldPublishing();
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Isolation', 'debug' => 0, 'engine' => 'twig', 'key' => 'folio-render-isolation-test-key-0123', 'url' => 'https://site.test', 'locale' => 'en'];\n");
        $this->write('config/i18n.php', "<?php\nreturn ['default' => 'en', 'available' => ['en', 'de'], 'fallback' => 'en', 'url_strategy' => 'none', 'cookie' => false];\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, " . var_export(IsolationProbeProvider::class, true) . "];\n");
        $this->write('config/middleware.php', "<?php\nreturn [" . var_export(IsolationSideEffects::class, true) . "];\n");
        $this->write('config/notify.php', "<?php\nreturn ['transports' => ['mail' => ['driver' => 'memory']], 'channels' => ['email' => ['transport' => 'mail', 'from' => 'site@site.test']]];\n");
        $this->write('resources/folio/frontend/post.twig', '<html><head><title>{{ seo_title() }}</title>{{ head() }}</head><body><main><h1>{{ record.title }}</h1>'
            . '<p>{{ folio_find("post", "p1").title|default("none") }}|' . self::LINE . '</p></main>{{ assets() }}</body></html>');
        $this->write('templates/isolation.twig', '<p>{{ folio_find("post", "p1").title|default("none") }}|' . self::LINE . '</p>');

        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'Hello', 'de' => 'Hallo'], 'slug' => ['en' => 'hello', 'de' => 'hello'], '_locales' => ['en', 'de']]), null);
        $this->working('post', 'p1', ['title' => ['en' => 'Draft', 'de' => 'Entwurf'], 'slug' => ['en' => 'hello', 'de' => 'hello'], '_locales' => ['en', 'de']]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeScaffold();
    }

    protected function app(): Application
    {
        $app = Application::create($this->dir);
        $app->boot();

        return $app;
    }

    protected function context(): RenderContext
    {
        return ContextFactory::forTest(new Site('https://site.test', ['en', 'de'], 'en'))
            ->for(new ServerRequest('GET', 'https://site.test/hello'));
    }

    protected function clockFormat(): ?string
    {
        return DATE_ATOM;
    }

    protected function paths(): array
    {
        $template = fn (): string => $this->dir . '/templates/isolation.twig';

        return [
            'audit' => self::internal(Purpose::Audit),
            'geo' => self::internal(Purpose::Geo),
            'rebuild' => self::internal(Purpose::Rebuild),
            'verify' => static fn (Application $app, RenderContext $ctx): RenderResult
                => self::page($app, $ctx->discarding()->skippingWrites()->because(Purpose::Verify)),
            'preview' => static fn (Application $app, RenderContext $ctx): RenderResult => $app->preview($ctx, '/hello', $ctx->locale),
            'mail' => static fn (Application $app, RenderContext $ctx): RenderResult => self::caller(
                $ctx,
                static fn (): string => (string) $app->container()->get(Mailer::class)->compose(new MailContent('Isolation', 'Text', $ctx->locale), 'reader@site.test', template: $template())->html,
            ),
            'pdf' => static fn (Application $app, RenderContext $ctx): RenderResult => self::caller(
                $ctx,
                static fn (): string => $app->container()->get(PdfRenderer::class)->html(new IsolationDocument($template())),
            ),
        ];
    }

    /** The record page reads Folio content as the context says: published or working, in its locale. */
    public function test_the_page_shows_the_read_mode_and_the_locale_it_is_given(): void
    {
        $app = $this->app();
        $ctx = $this->context();

        $published = (string) self::page($app, $ctx->because(Purpose::Audit)->discarding()->skippingWrites())->response->getBody();
        $working = (string) $app->preview($ctx, '/hello', 'de')->response->getBody();

        $this->assertStringContainsString('<h1>Hello</h1><p>Hello|', $published);
        // The route's record is read as the context says too, as folio_find() reads (since 0.81.0;
        // 0.80 read it from the published table whatever the read mode).
        $this->assertStringContainsString('<h1>Entwurf</h1><p>Entwurf|', $working);
        $this->assertStringContainsString('|de|', $working);
        $this->assertSame(0, Contexts::depth());
    }

    /**
     * An internal render of /hello in the context's locale, frozen when the context is.
     *
     * @return \Closure(Application, RenderContext): RenderResult
     */
    private static function internal(Purpose $purpose): \Closure
    {
        return static function (Application $app, RenderContext $ctx) use ($purpose): RenderResult {
            $internal = RenderContext::internal($purpose, $ctx->site, $ctx->clock, '/hello')->in($ctx->locale);

            return self::page($app, $ctx->isFrozen() ? $internal->at($ctx->now) : $internal);
        };
    }

    private static function page(Application $app, RenderContext $ctx): RenderResult
    {
        return $app->render($ctx, $ctx->request?->request ?? new ServerRequest('GET', 'https://site.test/hello'));
    }

    /**
     * $render called by code that runs under $ctx in the caller's frame (the contract's outer
     * frame), as a request handler or a command calls the Mailer or the PdfRenderer: the entry
     * point opens its own frame. The result lists what reached the caller's frame during the call
     * (left there, so the frame test sees it too) and the writes it skipped.
     *
     * @param \Closure(): string $render
     */
    private static function caller(RenderContext $ctx, \Closure $render): RenderResult
    {
        $frame = Contexts::frame();
        $queued = self::queued($frame);
        $skipped = count($frame->skippedWrites());

        $html = Contexts::within($ctx->capturing(), $render);

        return new RenderResult(
            new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $html),
            array_slice(self::queued($frame), count($queued)),
            array_slice($frame->skippedWrites(), $skipped),
        );
    }

    /** @return list<CapturedItem> what $frame has queued, left queued */
    private static function queued(RenderFrame $frame): array
    {
        $outbox = $frame->outbox();
        $items = $outbox->drain();
        foreach ($items as $item) {
            $outbox->queue($item->item, $item->channel);
        }

        return $items;
    }
}
