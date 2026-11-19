<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Context;

require_once __DIR__ . '/../Fixtures/Isolation/IsolationRoutes.php';
require_once __DIR__ . '/../Fixtures/Isolation/IsolationProvider.php';
require_once __DIR__ . '/../Fixtures/Isolation/IsolationDocument.php';

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Preflow\Core\Application;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\Purpose;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\RenderResult;
use Preflow\Core\Context\Site;
use Preflow\Core\Tests\Fixtures\Isolation\IsolationDocument;
use Preflow\Notify\Mail\MailContent;
use Preflow\Notify\Mail\MailLayout;
use Preflow\Testing\ContextFactory;
use Preflow\Testing\Contract\RenderIsolationContract;
use Preflow\View\Pdf\PdfRenderer;

/**
 * Core's internal render paths against RenderIsolationContract, over a fixture site
 * (Fixtures/Isolation/site): one Twig page printing the SEO title and the assets it rendered with,
 * and "{locale}|{in or out}|{the request's cookie m}|{render time}|{an overridable text}", and a
 * middleware that queues a cookie and a mail on every GET. Mail and PDF render the same from
 * templates/isolation.twig.
 *
 * Each path runs the render the way its caller does in 0.81: audit, GEO and rebuild as
 * internal renders through Application::render(), verify as the visitor's context discarding and
 * skipping writes, preview as a whole-page preview of working copies, mail in the recipient's
 * context, PDF in the caller's.
 */
#[RunTestsInSeparateProcesses]
final class CoreRenderIsolationTest extends RenderIsolationContract
{
    private const FIXTURE = __DIR__ . '/../Fixtures/Isolation/site';

    private ?string $dir = null;

    protected function tearDown(): void
    {
        parent::tearDown();
        if ($this->dir !== null && is_dir($this->dir)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->dir);
        }
    }

    protected function app(): Application
    {
        $this->dir = sys_get_temp_dir() . '/render_isolation_' . bin2hex(random_bytes(4));
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::FIXTURE, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $f) {
            $target = $this->dir . substr($f->getPathname(), strlen(self::FIXTURE));
            if ($f->isDir()) {
                @mkdir($target, 0777, true);
            } else {
                copy($f->getPathname(), $target);
            }
        }
        $app = Application::create($this->dir);
        $app->boot();

        return $app;
    }

    protected function context(): RenderContext
    {
        return ContextFactory::forTest(new Site('https://site.test', ['en', 'de'], 'en'));
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
            'preview' => static fn (Application $app, RenderContext $ctx): RenderResult
                => self::page($app, $ctx->asVisitor()->public()->workingCopies()->capturing()->skippingWrites()->because(Purpose::Preview)),
            'mail' => static fn (Application $app, RenderContext $ctx): RenderResult => self::own(
                $ctx->forRecipient($ctx->locale)->capturing()->skippingWrites(),
                static fn (): string => $app->container()->get(MailLayout::class)->html(new MailContent('Isolation', 'Text', $ctx->locale), '#000000', $template()),
            ),
            'pdf' => static fn (Application $app, RenderContext $ctx): RenderResult => self::own(
                $ctx->because(Purpose::Pdf)->discarding()->skippingWrites(),
                static fn (): string => $app->container()->get(PdfRenderer::class)->html(new IsolationDocument($template())),
            ),
        ];
    }

    /** What does not render under its own context yet; each path leaves when it does. */
    protected function pending(): array
    {
        $render = ['*' => 'Application::render() is not there yet'];
        $services = 'the translator, the signed-in user, the request, the assets and the page meta still come from the services, not the context';
        // The asset registry is shared by every render: head() prints the libraries only in the
        // first render of the process (mail runs before pdf, so pdf never prints them).
        $assets = 'the asset registry is shared by every render: head() prints the libraries only in the first one';

        return [
            'audit' => $render,
            'geo' => $render,
            'rebuild' => $render,
            'verify' => $render,
            'preview' => $render,
            'mail' => ['poison' => $services, 'interleave' => $assets, 'frozen' => $assets],
            'pdf' => ['poison' => $services, 'interleave' => 'the locale and the signed-in user come from the services: B renders like A'],
        ];
    }

    protected function clockFormat(): ?string
    {
        return DATE_ATOM;
    }

    /**
     * An internal render of the home page in the context's locale, frozen when the context is.
     *
     * @return \Closure(Application, RenderContext): RenderResult
     */
    private static function internal(Purpose $purpose): \Closure
    {
        return static function (Application $app, RenderContext $ctx) use ($purpose): RenderResult {
            $internal = RenderContext::internal($purpose, $ctx->site, $ctx->clock, '/')->in($ctx->locale);

            return self::page($app, ($ctx->jsonSerialize()['frozen'] ?? false) === true ? $internal->at($ctx->now) : $internal);
        };
    }

    private static function page(Application $app, RenderContext $ctx): RenderResult
    {
        return $app->render($ctx, $ctx->request?->request ?? new ServerRequest('GET', '/'));
    }

    /**
     * $render in its own frame under $ctx, as the mail and PDF renderers run it.
     *
     * @param \Closure(): string $render
     */
    private static function own(RenderContext $ctx, \Closure $render): RenderResult
    {
        $frame = null;
        $html = Contexts::run($ctx, static function () use (&$frame, $render): string {
            $frame = Contexts::frame();

            return $render();
        });

        return new RenderResult(new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $html), $frame->outbox()->drain(), $frame->skippedWrites());
    }
}
