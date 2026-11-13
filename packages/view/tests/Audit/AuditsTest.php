<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Audit;

require_once __DIR__ . '/Fixtures/AuditsTestApp.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Notify\SentMessage;
use Preflow\Notify\TransportFactory;
use Preflow\View\Audit\AuditRegistry;
use Preflow\View\Audit\AuditReport;
use Preflow\View\Audit\AuditRun;
use Preflow\View\Audit\AuditRunning;
use Preflow\View\Audit\Audits;
use Preflow\View\Audit\PageRenderer;
use Preflow\View\Seo\PublicPage;
use Preflow\View\Seo\PublicPages;
use Preflow\View\Tests\Audit\Fixtures\AuditsTestPages;
use Preflow\View\Tests\Audit\Fixtures\AuditsTestRoutes;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Audits: one step of the SEO audit at a time, the quick checks, the saved report and the lock probe. */
final class AuditsTest extends TestCase
{
    private string $dir;
    private string $file;
    private \DateTimeImmutable $clock;
    /** @var list<string> paths rendered, in order */
    private array $rendered = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/audits_' . bin2hex(random_bytes(4));
        $this->file = AuditReport::file($this->dir);
        $this->clock = new \DateTimeImmutable('2026-11-13T10:00:00+00:00');
    }

    protected function tearDown(): void
    {
        AuditsTestRoutes::$step = null;
        if (!is_dir($this->dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    /** Three public pages; each render takes one second on the fake clock. */
    private function audits(): Audits
    {
        $renderer = new PageRenderer(
            function (ServerRequestInterface $request): ResponseInterface {
                $this->rendered[] = $request->getUri()->getPath();
                $this->clock = $this->clock->modify('+1 second');

                return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], '<html><head><title>t</title></head><body>x</body></html>');
            },
            'https://site.test',
        );
        $public = new PublicPages();
        $public->source(static fn (string $locale): array => [
            new PublicPage('One', '/one', order: 0),
            new PublicPage('Two', '/two', order: 1),
            new PublicPage('Three', '/three', order: 2),
        ]);

        return new Audits(new AuditRegistry(), $renderer, $public, $this->file, fn (): \DateTimeImmutable => $this->clock);
    }

    public function test_steps_continue_the_saved_run_until_it_is_complete(): void
    {
        $audits = $this->audits();
        $this->assertNull($audits->report());
        $this->assertSame($this->file, $audits->file());

        $first = $audits->step(1);
        $this->assertFalse($first->complete());
        $this->assertSame(1, $first->done());
        $this->assertSame(1, $audits->report()?->done());

        // A new service (as the next web request gets) continues from the saved report.
        $second = $this->audits()->step(1);
        $this->assertSame(2, $second->done());
        $this->assertFalse($second->complete());

        $third = $this->audits()->step(1);
        $this->assertTrue($third->complete());
        $this->assertSame(3, $third->total);
        $this->assertSame(['/one', '/two', '/three'], $this->rendered);
        $this->assertTrue($this->audits()->report()?->complete());
    }

    public function test_fresh_starts_over(): void
    {
        $audits = $this->audits();
        $audits->step(1);
        $audits->step(1);

        $progress = [];
        $report = $audits->step(1, fresh: true, progress: static function (int $done, int $total, string $url) use (&$progress): void {
            $progress[] = "{$done}/{$total} {$url}";
        });

        $this->assertSame(1, $report->done());
        $this->assertSame(['/one', '/two', '/one'], $this->rendered);
        $this->assertSame(['1/3 https://site.test/one'], $progress);
    }

    public function test_a_step_while_another_run_holds_the_lock_throws_and_running_says_so(): void
    {
        $audits = $this->audits();
        $this->assertFalse($audits->running()); // no lock file yet
        $this->assertFileDoesNotExist(AuditRun::lockFile($this->file));

        $audits->step(1);
        $this->assertFileExists(AuditRun::lockFile($this->file));
        $this->assertFalse($audits->running()); // the step let go of it

        $lock = fopen(AuditRun::lockFile($this->file), 'c');
        $this->assertNotFalse($lock);
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $this->assertTrue($audits->running());
            $this->assertTrue($audits->running()); // the probe didn't take it
            try {
                $audits->step(1);
                $this->fail('A step while another run holds the lock throws.');
            } catch (AuditRunning $e) {
                $this->assertStringContainsString(AuditRun::lockFile($this->file), $e->getMessage());
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $this->assertFalse($audits->running());
        $this->assertSame(['/one'], $this->rendered);
        $this->assertSame(2, $audits->step(1)->done());
    }

    public function test_a_step_waits_a_moment_for_a_lock_held_only_briefly(): void
    {
        $audits = $this->audits();
        $audits->step(1); // makes the lock file

        // Another process (a running() probe, say) holds the lock for 10 ms.
        $holder = proc_open(
            [PHP_BINARY, '-r', '$f = fopen($argv[1], "r+"); flock($f, LOCK_EX); echo "locked\n"; fflush(STDOUT); usleep(10000); flock($f, LOCK_UN);', AuditRun::lockFile($this->file)],
            [1 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($holder);
        $this->assertSame("locked\n", fgets($pipes[1]));

        try {
            $report = $audits->step(1);
        } finally {
            fclose($pipes[1]);
            proc_close($holder);
        }

        $this->assertSame(2, $report->done());
    }

    public function test_running_creates_no_files(): void
    {
        $this->assertFalse($this->audits()->running());

        $this->assertDirectoryDoesNotExist($this->dir);
    }

    public function test_quick_checks_leave_the_report_file_alone(): void
    {
        $audits = $this->audits();
        $audits->step(1);
        $saved = (string) file_get_contents($this->file);

        $limited = $audits->quick(2, null, 600);
        $one = $audits->quick(null, '/three', 600);

        $this->assertTrue($limited->complete());
        $this->assertSame(2, $limited->total);
        $this->assertTrue($one->complete());
        $this->assertSame(1, $one->total);
        $this->assertSame($saved, (string) file_get_contents($this->file));
        $this->assertSame(1, $audits->report()?->done());
    }

    public function test_a_quick_check_without_a_limit_or_url_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->audits()->quick(null, null, 600);
    }

    /** A small app with mail (memory transport): /mail queues a mail, /admin/step runs a step. */
    private function app(): Application
    {
        @mkdir($this->dir . '/config', 0777, true);
        @mkdir($this->dir . '/app/pages', 0777, true);
        $fixture = var_export(__DIR__ . '/Fixtures/AuditsTestApp.php', true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'audits-test-key-0123456789abcdef01','url'=>'https://site.test'];\n");
        file_put_contents($this->dir . '/config/notify.php', "<?php\nreturn ['transports' => ['mail' => ['driver' => 'memory']], 'channels' => ['email' => ['transport' => 'mail', 'from' => 'site@example.com']]];\n");
        file_put_contents($this->dir . '/config/providers.php', "<?php\nrequire_once {$fixture};\nreturn [" . var_export(AuditsTestPages::class, true) . "];\n");
        file_put_contents($this->dir . '/config/middleware.php', "<?php\nrequire_once {$fixture};\nreturn [" . var_export(AuditsTestRoutes::class, true) . "];\n");

        $app = Application::create($this->dir);
        $app->boot();

        return $app;
    }

    /** @return list<string> who the app's sent mails went to */
    private static function sentTo(Application $app): array
    {
        return array_map(
            static fn (SentMessage $sent): string => $sent->message->to[0],
            $app->container()->get(TransportFactory::class)->get('mail')->sent(),
        );
    }

    #[RunInSeparateProcess]
    public function test_a_step_in_a_web_request_sends_no_mail_the_audited_page_queues(): void
    {
        $app = $this->app();
        $container = $app->container();
        $request = static fn (string $path): ServerRequestInterface => (new Psr17Factory())->createServerRequest('GET', 'https://site.test' . $path);

        // Proof the page queues a mail that the request's flush sends, for a visitor.
        $app->handle($request('/mail'));
        $this->assertSame(['visitor@example.com'], self::sentTo($app));

        // An admin request runs a step; its end flushes the queue: only its own mail goes out.
        $report = null;
        AuditsTestRoutes::$step = static function () use ($container, &$report): void {
            $report = $container->get(Audits::class)->step(20);
        };
        $response = $app->handle($request('/admin/step'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertInstanceOf(AuditReport::class, $report);
        $this->assertTrue($report->complete());
        $this->assertSame(1, $report->total);
        $this->assertSame(['visitor@example.com', 'admin@example.com'], self::sentTo($app));

        // And it isn't left in the queue for a later flush either.
        $app->handle($request('/nothing-here'));
        $this->assertSame(['visitor@example.com', 'admin@example.com'], self::sentTo($app));
    }

    #[RunInSeparateProcess]
    public function test_the_service_is_bound_and_built_on_first_use_only(): void
    {
        $app = $this->app();
        $container = $app->container();

        $this->assertTrue($container->bound(Audits::class));
        $app->handle((new Psr17Factory())->createServerRequest('GET', 'https://site.test/mail'));
        $this->assertFalse($container->resolved(Audits::class));
        $this->assertFalse($container->resolved(PageRenderer::class));

        $audits = $container->get(Audits::class);
        $this->assertSame($audits, $container->get(Audits::class));
        $this->assertSame(AuditReport::file($this->dir), $audits->file());
    }
}
