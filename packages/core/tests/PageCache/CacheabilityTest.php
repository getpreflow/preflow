<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\PageCache;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\PageCache\Cacheability;
use Preflow\Core\Tests\PageCache\Fixtures\CookieRoutes;
use Preflow\Core\Tests\PageCache\Fixtures\StampCard;
use Preflow\Testing\ChecksFrameBalance;

require_once __DIR__ . '/Fixtures/CookieRoutes.php';
require_once __DIR__ . '/Fixtures/StampCard.php';

/** Cacheability: why a page would or would not be stored, found outside a request. */
final class CacheabilityTest extends TestCase
{
    use ChecksFrameBalance;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pcwhy_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        unset($_SESSION);
        CookieRoutes::$mode = '';
        ini_set('error_log', $this->dir . '/php.log');
    }

    protected function tearDown(): void
    {
        CookieRoutes::$mode = '';
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function site(int $debug = 0, bool $cookies = false): void
    {
        $this->write('config/app.php', "<?php\nreturn ['name'=>'t','debug'=>{$debug},'engine'=>'twig','key'=>'page-cache-test-key-0123456789ab','url'=>'https://example.com','locale'=>'en'];\n");
        $this->write('config/page_cache.php', "<?php return ['enabled' => true, 'verify' => 'off'];");
        $this->write('config/data.php', "<?php\nreturn ['default'=>'json','drivers'=>['json'=>['path'=>'{$this->dir}/storage/data']]];\n");
        if ($cookies) {
            $this->write('config/middleware.php', '<?php return [' . var_export(CookieRoutes::class, true) . '];');
        }
        $this->write('app/pages/static.twig', '<html><head><title>S</title></head><body><main><p>same for everyone</p></main></body></html>');
        $this->write('app/pages/stamped.twig', '<html><head><title>S</title></head><body><main>{{ component("' . addslashes(StampCard::class) . '") }}</main></body></html>');
    }

    private function check(string $path, ?string $locale = null): \Preflow\Core\PageCache\CacheabilityResult
    {
        $app = Application::create($this->dir);
        $app->boot();

        return $app->container()->get(Cacheability::class)->check($path, $locale);
    }

    private function stored(): int
    {
        $root = $this->dir . '/storage/cache/pages';
        if (!is_dir($root)) {
            return 0;
        }
        $files = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $files += $file->isFile() ? 1 : 0;
        }

        return $files;
    }

    #[RunInSeparateProcess]
    public function test_static_page_would_be_cached(): void
    {
        $this->site();

        $result = $this->check('/static');

        $this->assertTrue($result->cacheable);
        $this->assertNull($result->reason);
        $this->assertNull($result->hint);
    }

    #[RunInSeparateProcess]
    public function test_per_visitor_part_is_named(): void
    {
        $this->site();

        $result = $this->check('/stamped');

        $this->assertFalse($result->cacheable);
        $this->assertStringContainsString('StampCard-', (string) $result->reason, 'the component');
        $this->assertSame(2, preg_match_all('/[0-9a-f]{16}/', (string) $result->reason), 'a piece of each render');
        $this->assertStringContainsString('live block', (string) $result->hint);
        $this->assertStringContainsString('#[Live]', (string) $result->hint);
    }

    #[RunInSeparateProcess]
    public function test_reports_refusal_reason_not_difference(): void
    {
        $this->site(cookies: true);
        CookieRoutes::$mode = 'before';

        $result = $this->check('/static');

        $this->assertFalse($result->cacheable);
        $this->assertStringContainsString('cookie', (string) $result->reason);
        $this->assertStringNotContainsString('differs', (string) $result->reason);
    }

    #[RunInSeparateProcess]
    public function test_a_missing_page_is_a_status_refusal(): void
    {
        $this->site();

        $result = $this->check('/nowhere');

        $this->assertFalse($result->cacheable);
        $this->assertSame(404, $result->status);
        $this->assertStringContainsString('404', (string) $result->reason);
    }

    #[RunInSeparateProcess]
    public function test_works_with_debug_on(): void
    {
        $this->site(debug: 2);

        $static = $this->check('/static');
        $stamped = $this->check('/stamped');

        $this->assertTrue($static->cacheable);
        $this->assertFalse($stamped->cacheable);
        $this->assertStringContainsString('StampCard-', (string) $stamped->reason);
        $this->assertSame(0, $this->stored(), 'nothing is stored, with debug on or off');
    }

    #[RunInSeparateProcess]
    public function test_nothing_is_stored_with_debug_off(): void
    {
        $this->site();

        $this->check('/static');

        $this->assertSame(0, $this->stored());
    }

    #[RunInSeparateProcess]
    public function test_a_debug_app_does_not_rebuild_or_store_a_rebuild_request(): void
    {
        $this->site(debug: 2);
        $app = Application::create($this->dir);
        $app->setRunningInConsole(false);
        $app->boot();

        $response = $app->handle(\Preflow\Core\PageCache\Rebuild::request('https://example.com/static'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', $response->getHeaderLine('X-Page-Cache'), 'the cache took no part');
        $this->assertSame(0, $this->stored());
    }

    #[RunInSeparateProcess]
    public function test_a_probe_logs_nothing(): void
    {
        $this->site();

        $this->check('/stamped');

        $this->assertSame('', (string) @file_get_contents($this->dir . '/php.log'));
    }

    private function write(string $rel, string $content): void
    {
        $path = $this->dir . '/' . $rel;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);
    }
}
