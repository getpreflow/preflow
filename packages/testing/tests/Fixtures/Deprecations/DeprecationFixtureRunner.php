<?php

declare(strict_types=1);

namespace Preflow\Testing\Tests\Fixtures\Deprecations;

use Nyholm\Psr7\Response;
use Preflow\Auth\AuthManager;
use Preflow\Core\Application;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\Purpose;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\RenderResult;
use Preflow\Core\Context\Site;
use Preflow\I18n\Translator;
use Preflow\Testing\ContextFactory;
use Preflow\Testing\Contract\RenderIsolationContract;

/**
 * A site runner of the render isolation contract, as a site writes it: no #[IgnoreDeprecations],
 * no Deprecations::reset(). Its app binds a translator and an auth manager, so the contract's
 * poison calls their deprecated setters (Translator::setLocale(), useOverrides(),
 * AuthManager::setUser()). RenderIsolationContractTest runs the runners extending it in a PHPUnit
 * subprocess under this folder's phpunit.xml (failOnDeprecation, as a site's).
 */
abstract class DeprecationFixtureRunner extends RenderIsolationContract
{
    /** The page the path prints: what it renders under. */
    abstract protected function page(RenderContext $ctx): string;

    protected function app(): Application
    {
        $app = Application::create(['app' => ['name' => 'deprecation-fixture', 'key' => 'deprecation-fixture-key-0123456789']]);
        $app->container()->instance(Translator::class, new Translator(__DIR__, 'en', 'en'));
        $app->container()->instance(AuthManager::class, new AuthManager([], 'web'));

        return $app;
    }

    protected function context(): RenderContext
    {
        return ContextFactory::forTest(new Site('https://site.test', ['en', 'de'], 'en'));
    }

    protected function paths(): array
    {
        return [
            'audit' => function (Application $app, RenderContext $ctx): RenderResult {
                $frame = null;
                $body = Contexts::run($ctx->because(Purpose::Audit)->discarding()->skippingWrites(), function () use (&$frame): string {
                    $frame = Contexts::frame();

                    return $this->page(Contexts::current());
                });

                return new RenderResult(new Response(200, [], $body), $frame->madeOutbox()?->drain() ?? [], $frame->skippedWrites());
            },
        ];
    }

    /** What a page shows of its context: locale, viewer and reads (the interleaved test needs B to differ). */
    protected static function describe(RenderContext $ctx): string
    {
        return $ctx->locale . '|' . $ctx->viewer->key() . '|' . $ctx->reads->key();
    }
}
