<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Context;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\Deprecations;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\Site;
use Preflow\Core\Context\SystemClock;
use Preflow\Core\Http\LocaleUrls;

final class DeprecationsTest extends TestCase
{
    /** @var list<array{int, string, string, int}> */
    private array $notices = [];

    protected function setUp(): void
    {
        Contexts::reset();
        Deprecations::reset();
        set_error_handler(function (int $level, string $message, string $file, int $line): bool {
            $this->notices[] = [$level, $message, $file, $line];

            return true;
        }, E_USER_DEPRECATED);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        Contexts::reset();
    }

    /** Review Focus 5: a site's setLocale() per list item. */
    public function test_deprecations_once_reports_each_call_site_once(): void
    {
        $site = new Site('https://example.test', ['en', 'de', 'fr'], 'en', new LocaleUrls(['en', 'de', 'fr'], 'en'));
        $ctx = RenderContext::fromRequest(new ServerRequest('GET', '/'), $site, new SystemClock());

        Contexts::run($ctx, function (): void {
            for ($i = 0; $i < 50; $i++) {
                $locale = $i % 2 === 0 ? 'de' : 'fr';
                LoopShim::setLocale($locale);
                $this->assertSame($locale, Contexts::current()->locale, 'every call still narrows the frame');
            }

            $this->assertCount(1, $this->notices, 'one notice for one call site, however often it runs');
            $this->assertSame(E_USER_DEPRECATED, $this->notices[0][0]);
            $this->assertSame(LoopShim::MESSAGE, $this->notices[0][1], 'the message is passed on as written');

            LoopShim::setLocale('en');
            $this->assertCount(2, $this->notices, 'a second call site gets its own notice');
            $this->assertSame('en', Contexts::current()->locale);

            for ($i = 0; $i < 3; $i++) {
                LoopShim::setLocale('de');
                LoopShim::setLocale('fr');
            }
            $this->assertCount(4, $this->notices, 'two more call sites, one notice each');
            $this->assertSame('fr', Contexts::current()->locale);
        });
    }

    public function test_reset_lets_a_call_site_warn_again(): void
    {
        for ($i = 0; $i < 2; $i++) {
            Deprecations::once(LoopShim::MESSAGE, 1);
            Deprecations::reset();
        }

        $this->assertCount(2, $this->notices, 'tests see the notice whatever ran before them');
    }

    /** A deliberate call (a test exercising a shim on purpose) neither warns nor uses up the call site's notice. */
    public function test_expected_calls_neither_warn_nor_record(): void
    {
        $result = Deprecations::expected(static function (): string {
            Deprecations::once(LoopShim::MESSAGE, 1);

            return 'done';
        });

        $this->assertSame('done', $result, 'the closure\'s result is passed back');
        $this->assertSame([], $this->notices);
        Deprecations::once(LoopShim::MESSAGE, 1);
        $this->assertCount(1, $this->notices, 'the same call site outside warns: nothing was recorded');
    }

    public function test_expected_nests_and_restores_after_a_throw(): void
    {
        Deprecations::expected(static function (): void {
            Deprecations::expected(static function (): void {
                Deprecations::once('inner', 1);
            });
            Deprecations::once('outer', 1);
        });
        $this->assertSame([], $this->notices, 'the outer stretch is still expected after the inner one ended');

        try {
            Deprecations::expected(static function (): void {
                throw new \RuntimeException('failed');
            });
        } catch (\RuntimeException) {
        }
        Deprecations::once('after', 1);
        $this->assertCount(1, $this->notices, 'a throw ends the expected stretch');
    }
}

/** A shim as 0.81 writes them: it warns once per call site and still does its work. */
final class LoopShim
{
    public const MESSAGE = 'LoopShim::setLocale() is deprecated since 0.81 and is removed in 0.83; use Contexts::narrow().';

    public static function setLocale(string $locale): void
    {
        Deprecations::once(self::MESSAGE);
        Contexts::narrow(static fn (RenderContext $c): RenderContext => $c->in($locale));
    }
}
