<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Http;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\Purpose;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\Site;
use Preflow\Core\Context\SystemClock;
use Preflow\Core\Http\InternalRender;

/** Since 0.81 the render's purpose says whether it is internal, never the request. */
final class InternalRenderTest extends TestCase
{
    protected function setUp(): void
    {
        Contexts::reset();
    }

    protected function tearDown(): void
    {
        Contexts::reset();
    }

    private static function in(Purpose $purpose): bool
    {
        $site = new Site('http://localhost', ['en'], 'en');

        return Contexts::run(
            RenderContext::internal($purpose, $site, new SystemClock(), '/'),
            static fn (): bool => InternalRender::is(new ServerRequest('GET', '/')),
        );
    }

    public function test_a_plain_request_is_not_an_internal_render(): void
    {
        $this->assertFalse(InternalRender::is(new ServerRequest('GET', '/')));
    }

    public function test_renders_whose_purpose_leaves_no_trace_are_internal(): void
    {
        $this->assertTrue(self::in(Purpose::Audit));
        $this->assertTrue(self::in(Purpose::Geo));
        $this->assertTrue(self::in(Purpose::Rebuild));
        $this->assertTrue(self::in(Purpose::Preview));
        $this->assertFalse(self::in(Purpose::Visit));
        $this->assertFalse(self::in(Purpose::Verify));
    }

    public function test_a_request_attribute_alone_makes_nothing_internal(): void
    {
        // The attribute the removed mark() set (before 0.83): the purpose decides, not the request.
        $marked = (new ServerRequest('GET', '/'))->withAttribute('preflow.internal_render', true);

        $this->assertFalse(InternalRender::is($marked));
        $this->assertFalse(Contexts::run(
            RenderContext::fromRequest($marked, new Site('http://localhost', ['en'], 'en'), new SystemClock()),
            static fn (): bool => InternalRender::is($marked),
        ));
    }
}
