<?php

declare(strict_types=1);

namespace Preflow\Testing\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Context\FrozenClock;
use Preflow\Core\Context\OutboxPolicy;
use Preflow\Core\Context\Purpose;
use Preflow\Core\Context\Site;
use Preflow\Core\Context\WritePolicy;
use Preflow\Testing\ContextFactory;

final class ContextFactoryTest extends TestCase
{
    public function test_for_test_is_a_get_of_the_home_page_at_a_frozen_time(): void
    {
        $ctx = ContextFactory::forTest();

        $this->assertSame(Purpose::Test, $ctx->purpose);
        $this->assertSame('GET', $ctx->request?->method);
        $this->assertSame('/', $ctx->request->path);
        $this->assertSame('http://localhost/', (string) $ctx->request->request?->getUri());
        $this->assertInstanceOf(FrozenClock::class, $ctx->clock);
        $this->assertSame('2026-01-01T00:00:00+00:00', $ctx->now->format(DATE_ATOM));
        $this->assertSame('2026-01-01T00:00:00+00:00', $ctx->clock->now()->format(DATE_ATOM));
        $this->assertTrue($ctx->viewer->isAnonymous());
        $this->assertSame('en', $ctx->locale);
        $this->assertFalse($ctx->reads->readsWorkingCopies());
        $this->assertSame(OutboxPolicy::Deliver, $ctx->outbox);
        $this->assertSame(WritePolicy::Allow, $ctx->writes);
        $this->assertTrue($ctx->isPublicDefault(), 'a FrozenClock is not a context frozen with at()');
    }

    public function test_for_test_renders_the_given_site(): void
    {
        $site = new Site('https://site.test', ['de', 'en'], 'de');

        $ctx = ContextFactory::forTest($site);

        $this->assertSame($site, $ctx->site);
        $this->assertSame('de', $ctx->locale);
        $this->assertSame('https://site.test/', (string) $ctx->request?->request?->getUri());
    }

    public function test_each_call_builds_an_equal_context(): void
    {
        $this->assertSame(serialize(ContextFactory::forTest()), serialize(ContextFactory::forTest()));
    }
}
