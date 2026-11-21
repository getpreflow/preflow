<?php

declare(strict_types=1);

namespace Preflow\Htmx\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\Site;
use Preflow\Core\Context\SystemClock;
use Preflow\Core\Exceptions\SecurityException;
use Preflow\Htmx\ComponentToken;

/** A component token's issue time and its age check read the current render's clock. */
final class ComponentTokenClockTest extends TestCase
{
    private const string KEY = 'test-secret-key-32-chars-long!!';

    protected function tearDown(): void
    {
        Contexts::reset();
    }

    private static function at(string $when): RenderContext
    {
        return RenderContext::console(new Site('http://localhost', ['en'], 'en'), new SystemClock())->at(new \DateTimeImmutable($when));
    }

    /** Regression: decode() compared with the system clock, not with the clock seam encode() used. */
    public function test_component_token_expiry_follows_the_context_clock(): void
    {
        $token = Contexts::run(self::at('2026-01-01T12:00:00Z'), static fn (): string => (new ComponentToken(self::KEY))->encode('App\\Components\\Hero', ['id' => '1']));

        $issued = Contexts::run(self::at('2026-01-01T12:30:00Z'), static fn (): int => (new ComponentToken(self::KEY))->decode($token, 3600)->timestamp);
        $this->assertSame((new \DateTimeImmutable('2026-01-01T12:00:00Z'))->getTimestamp(), $issued, 'issued at the context\'s time, valid half an hour later');

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('Component token expired.');
        Contexts::run(self::at('2026-01-03T12:00:00Z'), static fn (): mixed => (new ComponentToken(self::KEY))->decode($token, 3600));
    }
}
