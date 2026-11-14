<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\PageCache;

require_once __DIR__ . '/../Fixtures/Geo/GeoMailRoutes.php';

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\PageCache\PageCacheConfig;
use Preflow\Core\Tests\Fixtures\Geo\GeoMailRoutes;
use Preflow\Notify\SentMessage;
use Preflow\Notify\TransportFactory;

/**
 * Since 0.75 the page cache's verify render (the page rendered once more as another visitor) drops
 * the mail it queues, as an internal render does (Notifier::discardingQueued()): a GET page that
 * queues a mail sends it once per visit, not twice on a verified miss.
 */
final class VerifyMailTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pcverify_mail_' . bin2hex(random_bytes(4));
        @mkdir($this->dir . '/config', 0777, true);
        @mkdir($this->dir . '/app/pages', 0777, true);
        unset($_SESSION);
        ini_set('error_log', $this->dir . '/php.log');
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'verify-mail-test-key-0123456789ab','url'=>'https://example.com'];\n");
        file_put_contents($this->dir . '/config/page_cache.php', "<?php\nreturn ['enabled' => true, 'explain' => true];\n");
        file_put_contents($this->dir . '/config/notify.php', "<?php\nreturn ['transports' => ['mail' => ['driver' => 'memory']], 'channels' => ['email' => ['transport' => 'mail', 'from' => 'site@example.com']]];\n");
        file_put_contents($this->dir . '/config/middleware.php', "<?php\nreturn [" . var_export(GeoMailRoutes::class, true) . "];\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    #[RunInSeparateProcess]
    public function test_a_verified_miss_sends_the_page_s_mail_once(): void
    {
        $app = Application::create($this->dir);
        $app->setRunningInConsole(false);
        $app->setPageCacheVerify(PageCacheConfig::VERIFY_ALWAYS);
        $app->boot();

        $response = $app->handle(new ServerRequest('GET', 'https://example.com/mail'));

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('miss', $response->getHeaderLine('X-Page-Cache'), 'stored after the verify render: ' . (string) @file_get_contents($this->dir . '/php.log'));
        $sent = array_map(
            static fn (SentMessage $s): string => $s->message->to[0],
            $app->container()->get(TransportFactory::class)->get('mail')->sent(),
        );
        $this->assertSame(['page@example.com'], $sent, 'the visitor\'s render sends it; the verify render does not');
    }
}
