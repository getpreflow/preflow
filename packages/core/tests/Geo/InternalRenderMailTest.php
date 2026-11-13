<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Geo;

require_once __DIR__ . '/../Fixtures/Geo/GeoMailRoutes.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Http\InternalRender;
use Preflow\Core\Tests\Fixtures\Geo\GeoMailRoutes;
use Preflow\Notify\Email;
use Preflow\Notify\Notifier;
use Preflow\Notify\SentMessage;
use Preflow\Notify\TransportFactory;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Since 0.74 an internal render drops the mail its page queues (core's anonymous render scope,
 * Notifier::discardingQueued()): a GEO Markdown view of a page that queues a mail sends none
 * when the request flushes, while the mail the request itself queued still goes out.
 */
final class InternalRenderMailTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/geo_mail_' . bin2hex(random_bytes(4));
        @mkdir($this->dir . '/config', 0777, true);
        @mkdir($this->dir . '/app/pages', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'geo-mail-test-key-0123456789abcdef','url'=>'https://site.test'];\n");
        file_put_contents($this->dir . '/config/geo.php', "<?php\nreturn ['enabled' => true];\n");
        file_put_contents($this->dir . '/config/notify.php', "<?php\nreturn ['transports' => ['mail' => ['driver' => 'memory']], 'channels' => ['email' => ['transport' => 'mail', 'from' => 'site@example.com']]];\n");
        file_put_contents($this->dir . '/config/middleware.php', "<?php\nreturn [" . var_export(GeoMailRoutes::class, true) . "];\n");
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    /** @return list<string> who the sent mails went to */
    private static function sentTo(Application $app): array
    {
        return array_map(
            static fn (SentMessage $sent): string => $sent->message->to[0],
            $app->container()->get(TransportFactory::class)->get('mail')->sent(),
        );
    }

    #[RunInSeparateProcess]
    public function test_a_markdown_view_sends_no_mail_its_page_queues_but_the_request_s_own_mail_goes_out(): void
    {
        $app = Application::create($this->dir);
        // The visitor's request queues its own mail before anything renders (not the internal render).
        $app->onRequest(static function (ServerRequestInterface $request) use ($app): void {
            if (!InternalRender::is($request)) {
                $app->container()->get(Notifier::class)->sendLater(new Email('request@example.com', 'Own', 'The request\'s own mail.'));
            }
        });
        $app->boot();
        $get = static fn (string $path): ServerRequestInterface => (new Psr17Factory())->createServerRequest('GET', 'https://site.test' . $path);

        // Proof: the page's mail goes out with the request's own when a visitor opens the page.
        $app->handle($get('/mail'));
        $this->assertSame(['request@example.com', 'page@example.com'], self::sentTo($app));

        // Its Markdown view renders the page internally: only the request's own mail goes out.
        $markdown = $app->handle($get('/mail.md'));
        $this->assertSame(200, $markdown->getStatusCode(), (string) $markdown->getBody());
        $this->assertStringContainsString('# Mail', (string) $markdown->getBody());
        $this->assertSame(['request@example.com', 'page@example.com', 'request@example.com'], self::sentTo($app));
    }
}
