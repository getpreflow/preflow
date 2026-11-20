<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\PageCache;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\Purpose;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\Site;
use Preflow\Core\Context\SystemClock;
use Preflow\Core\PageCache\AnonymousGlobals;
use Preflow\Testing\ChecksFrameBalance;

/** The superglobal adapter: internal frames see an anonymous visitor's superglobals, every other frame the real ones. */
final class AnonymousGlobalsAdapterTest extends TestCase
{
    use ChecksFrameBalance;

    protected function setUp(): void
    {
        Contexts::reset();
    }

    protected function tearDown(): void
    {
        Contexts::reset();
    }

    private static function site(): Site
    {
        return new Site('https://site.test', ['en'], 'en');
    }

    #[RunInSeparateProcess]
    public function test_internal_frames_swap_the_superglobals_and_put_them_back(): void
    {
        Contexts::onEnter(AnonymousGlobals::adapter(), AnonymousGlobals::HOOK);
        // Registered again (a second app boot in the same process): still one adapter.
        Contexts::onEnter(AnonymousGlobals::adapter(), AnonymousGlobals::HOOK);
        $_COOKIE = ['m' => 'visitor'];
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $_SESSION = ['_auth_user_id' => 'member'];
        $_GET = ['q' => 'visitor'];
        $seen = [];
        $internal = RenderContext::internal(Purpose::Rebuild, self::site(), new SystemClock(), '/list');
        $rebuild = $internal->for((new ServerRequest('GET', 'https://site.test/list?page=2'))->withQueryParams(['page' => '2']));

        Contexts::run($rebuild, static function () use (&$seen, $internal): void {
            $seen[] = [$_COOKIE, $_SERVER['REMOTE_ADDR'], isset($_SESSION), $_GET];
            // A nested internal render swaps again and gives the outer one back.
            Contexts::run($internal->because(Purpose::Audit), static function () use (&$seen): void {
                $seen[] = [$_COOKIE, $_SERVER['REMOTE_ADDR'], isset($_SESSION), $_GET];
            });
            $seen[] = [$_COOKIE, $_SERVER['REMOTE_ADDR'], isset($_SESSION), $_GET];
        });
        Contexts::run(RenderContext::console(self::site(), new SystemClock()), static function () use (&$seen): void {
            $seen[] = [$_COOKIE, $_SERVER['REMOTE_ADDR'], isset($_SESSION), $_GET];
        });

        $this->assertSame([
            [[], '127.0.0.1', false, ['page' => '2']],
            [[], '127.0.0.1', false, []],
            [[], '127.0.0.1', false, ['page' => '2']],
            [['m' => 'visitor'], '203.0.113.9', true, ['q' => 'visitor']],
        ], $seen);
        $this->assertSame(['m' => 'visitor'], $_COOKIE);
        $this->assertSame('203.0.113.9', $_SERVER['REMOTE_ADDR']);
        $this->assertSame(['_auth_user_id' => 'member'], $_SESSION);
    }

    #[RunInSeparateProcess]
    public function test_a_render_that_throws_gets_the_superglobals_back(): void
    {
        Contexts::onEnter(AnonymousGlobals::adapter(), AnonymousGlobals::HOOK);
        $_COOKIE = ['m' => 'visitor'];

        try {
            Contexts::run(RenderContext::internal(Purpose::Geo, self::site(), new SystemClock(), '/'), static function (): void {
                throw new \RuntimeException('failed');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(['m' => 'visitor'], $_COOKIE);
    }

    public function test_session_is_written_with_the_visitors_data_when_the_render_never_returns(): void
    {
        $dir = sys_get_temp_dir() . '/sess_adapter_' . bin2hex(random_bytes(4));
        mkdir($dir);
        $id = 'adapter' . bin2hex(random_bytes(8));

        try {
            $cmd = [PHP_BINARY, __DIR__ . '/../Fixtures/SessionGuard/fatal_during_internal_render.php',
                dirname(__DIR__, 4) . '/vendor/autoload.php', $dir, $id];
            exec(implode(' ', array_map('escapeshellarg', $cmd)) . ' 2>&1', $output, $code);

            $this->assertSame(0, $code, implode("\n", $output));
            $written = (string) @file_get_contents($dir . '/sess_' . $id);
            $this->assertStringContainsString('_auth_user_id|s:6:"member"', $written);
        } finally {
            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
        }
    }
}
