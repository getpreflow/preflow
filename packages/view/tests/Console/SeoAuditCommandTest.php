<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Console;

require_once __DIR__ . '/Fixtures/SeoAuditApp.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\DevTools\Console;
use Preflow\Notify\TransportFactory;
use Preflow\View\Audit\AuditReport;
use Preflow\View\Console\SeoAuditCommand;
use Preflow\View\Tests\Console\Fixtures\SeoAuditPages;
use Preflow\View\Tests\Console\Fixtures\SeoAuditRoutes;

/** preflow seo:audit [--fresh] [--limit=N] [--url=/path] [--budget=600] on a small app (JSON storage, no SQL). */
final class SeoAuditCommandTest extends TestCase
{
    private string $dir;

    /** @var resource */
    private $err;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/seo_audit_cmd_' . bin2hex(random_bytes(4));
        @mkdir($this->dir . '/config', 0777, true);
        @mkdir($this->dir . '/app/pages', 0777, true);
        $fixture = var_export(__DIR__ . '/Fixtures/SeoAuditApp.php', true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'seo-audit-cmd-test-key-0123456789ab','url'=>'https://site.test'];\n");
        file_put_contents($this->dir . '/config/notify.php', "<?php\nreturn ['transports' => ['mail' => ['driver' => 'memory']], 'channels' => ['email' => ['transport' => 'mail', 'from' => 'site@example.com']]];\n");
        file_put_contents($this->dir . '/config/providers.php', "<?php\nrequire_once {$fixture};\nreturn [" . var_export(SeoAuditPages::class, true) . "];\n");
        file_put_contents($this->dir . '/config/middleware.php', "<?php\nrequire_once {$fixture};\nreturn [" . var_export(SeoAuditRoutes::class, true) . "];\n");
        file_put_contents($this->dir . '/app/pages/about.twig', '<html><head><title>About</title></head><body><main><h1>About</h1><a href="/nowhere">x</a></main></body></html>');
        file_put_contents($this->dir . '/app/pages/contact.twig', '<html><head><title>Contact</title></head><body><main><h1>Contact</h1></main></body></html>');
        $this->err = fopen('php://memory', 'w+');
        SeoAuditPages::$paths = ['/about', '/contact'];
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    private ?Application $app = null;

    private function app(): Application
    {
        if ($this->app === null) {
            $this->app = Application::create($this->dir);
            $this->app->boot();
        }

        return $this->app;
    }

    /**
     * @param list<string> $args
     * @return array{0: int, 1: string, 2: string} exit code, output, errors
     */
    private function audit(array $args, bool $tty = false, ?\Closure $factory = null): array
    {
        $command = new SeoAuditCommand($factory ?? fn (): Application => $this->app(), $this->err, $tty);
        ob_start();
        try {
            $code = $command->execute($args);
        } finally {
            $out = (string) ob_get_clean();
        }
        rewind($this->err);
        $err = (string) stream_get_contents($this->err);
        ftruncate($this->err, 0);
        rewind($this->err);

        return [$code, $out, $err];
    }

    private function report(): string
    {
        return AuditReport::file($this->dir);
    }

    public function test_the_package_lists_the_command_and_the_console_finds_it(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);
        $this->assertContains(SeoAuditCommand::class, $composer['extra']['preflow']['commands'] ?? []);

        @mkdir($this->dir . '/vendor/composer', 0777, true);
        file_put_contents($this->dir . '/vendor/composer/installed.json', (string) json_encode(['packages' => [
            ['name' => $composer['name'], 'extra' => $composer['extra']],
        ]]));
        ob_start();
        $code = (new Console($this->dir, $this->err))->run(['preflow', 'help']);
        $out = (string) ob_get_clean();

        $this->assertSame(0, $code);
        $this->assertMatchesRegularExpression('/^  seo:audit\s+\S/m', $out);
        rewind($this->err);
        $this->assertSame('', (string) stream_get_contents($this->err));
    }

    #[RunInSeparateProcess]
    public function test_a_full_run_writes_the_report_and_prints_counts_and_findings(): void
    {
        [$code, $out, $err] = $this->audit([]);

        $this->assertSame(0, $code, $err);
        $this->assertSame('', $err);
        $this->assertStringContainsString("1/2\n2/2\n", $out);
        $report = AuditReport::load($this->report());
        $this->assertNotNull($report);
        $this->assertTrue($report->complete());
        $counts = $report->counts();
        $this->assertStringContainsString(sprintf("Critical: %d\nWarning:  %d\nNotice:   %d\n", $counts['critical'], $counts['warning'], $counts['notice']), $out);
        // Grouped by severity, then check: the heading, the check id, then url (locale): message.
        $this->assertMatchesRegularExpression('~\nCritical\n  link\.broken\n    https://site\.test/about \(en\): \S.*\n~', $out);
        $this->assertMatchesRegularExpression('~\nWarning\n(  \S+\n(    .*\n)+)*  description\.missing\n    https://site\.test/about \(en\): \S.*\n    https://site\.test/contact \(en\): \S.*\n~', $out);
        $this->assertLessThan(strpos($out, "\nWarning\n"), strpos($out, "\nCritical\n"));
        $this->assertLessThan(strpos($out, "\nNotice\n"), strpos($out, "\nWarning\n"));
        $this->assertStringContainsString('Complete (2 of 2 pages). Report saved to ' . AuditReport::PATH . ".\n", $out);
    }

    #[RunInSeparateProcess]
    public function test_progress_rewrites_one_line_on_a_terminal(): void
    {
        [$code, $out] = $this->audit([], tty: true);

        $this->assertSame(0, $code);
        $this->assertStringStartsWith("\r1/2\r2/2\n", $out);
    }

    #[RunInSeparateProcess]
    public function test_without_a_terminal_progress_prints_about_every_tenth(): void
    {
        SeoAuditPages::$paths = array_map(static fn (int $i): string => "/p{$i}", range(1, 25));

        [$code, $out] = $this->audit(['--limit=25']);

        $this->assertSame(0, $code);
        preg_match_all('~^(\d+)/25$~m', $out, $m);
        $this->assertSame(['1', '3', '5', '8', '10', '13', '15', '18', '20', '23', '25'], $m[1]);
    }

    #[RunInSeparateProcess]
    public function test_url_audits_one_page_only_and_leaves_the_report_alone(): void
    {
        [$code, $out, $err] = $this->audit(['--url=/contact']);

        $this->assertSame(0, $code, $err);
        $this->assertStringContainsString("1/1\n", $out);
        $this->assertStringContainsString('https://site.test/contact', $out);
        $this->assertStringNotContainsString('https://site.test/about', $out);
        $this->assertStringContainsString('Quick check: the saved report was left alone.', $out);
        $this->assertFileDoesNotExist($this->report());
    }

    #[RunInSeparateProcess]
    public function test_limit_prints_its_findings_but_never_writes_the_report(): void
    {
        [$code, $out] = $this->audit(['--limit=1']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString("1/1\n", $out);
        $this->assertStringContainsString('https://site.test/about (en)', $out);
        $this->assertStringContainsString('Quick check: the saved report was left alone.', $out);
        $this->assertFileDoesNotExist($this->report());
    }

    #[RunInSeparateProcess]
    public function test_a_budget_stop_prints_the_continue_line_and_the_next_run_continues(): void
    {
        SeoAuditPages::$paths = ['/slow', '/about'];

        [$code, $out] = $this->audit(['--budget=1']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString("1/2\n", $out);
        $this->assertStringNotContainsString('2/2', $out);
        $this->assertStringEndsWith("Incomplete (1 of 2 pages). Run `preflow seo:audit` again to continue.\n", $out);
        $this->assertSame(1, AuditReport::load($this->report())?->done());

        [$code, $out] = $this->audit([]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString("2/2\n", $out);
        $this->assertStringNotContainsString('1/2', $out);
        $this->assertStringContainsString('Complete (2 of 2 pages).', $out);
        $this->assertTrue(AuditReport::load($this->report())?->complete());
    }

    #[RunInSeparateProcess]
    public function test_fresh_starts_over(): void
    {
        SeoAuditPages::$paths = ['/slow', '/about'];
        $this->audit(['--budget=1']);

        [$code, $out] = $this->audit(['--fresh']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString("1/2\n2/2\n", $out);
    }

    #[RunInSeparateProcess]
    public function test_a_quick_check_stopped_by_the_budget_says_it_does_not_continue(): void
    {
        SeoAuditPages::$paths = ['/slow', '/about'];

        [$code, $out] = $this->audit(['--limit=2', '--budget=1']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Stopped at the time budget (1 of 2 pages).', $out);
        $this->assertStringNotContainsString('again to continue', $out);
        $this->assertFileDoesNotExist($this->report());
    }

    #[RunInSeparateProcess]
    public function test_another_running_audit_is_reported_with_exit_0(): void
    {
        @mkdir(dirname($this->report()), 0777, true);
        $lock = fopen($this->report() . '.lock', 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));

        try {
            [$code, $out] = $this->audit([]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Another SEO audit is running on this site', $out);
        $this->assertFileDoesNotExist($this->report());
    }

    public function test_bad_flags_exit_1_without_booting(): void
    {
        $boot = fn (): Application => throw new \LogicException('booted');
        foreach (['--limit=abc', '--limit=0', '--budget=0', '--budget=', '--url=', '--nope', 'extra', '--limit=2 --url=/a'] as $flags) {
            [$code, $out, $err] = $this->audit(explode(' ', $flags), factory: $boot);

            $this->assertSame(1, $code, $flags);
            $this->assertSame('', $out, $flags);
            $this->assertNotSame('', $err, $flags);
            $this->assertStringNotContainsString('booted', $err, $flags);
        }
    }

    public function test_help_explains_the_flags_and_exit_codes(): void
    {
        [$code, $out] = $this->audit(['--help'], factory: fn (): Application => throw new \LogicException('booted'));

        $this->assertSame(0, $code);
        foreach (['--fresh', '--limit=N', '--url=/path', '--budget=600', 'Another audit is running', 'Exit code'] as $text) {
            $this->assertStringContainsString($text, $out);
        }
    }

    public function test_a_boot_failure_exits_1(): void
    {
        [$code, $out, $err] = $this->audit([], factory: fn (): Application => throw new \RuntimeException('No router configured.'));

        $this->assertSame(1, $code);
        $this->assertSame('', $out);
        $this->assertStringContainsString('No router configured.', $err);
    }

    #[RunInSeparateProcess]
    public function test_an_audit_render_sends_no_mail(): void
    {
        SeoAuditPages::$paths = ['/mail'];

        [$code] = $this->audit([]);

        $this->assertSame(0, $code);
        $transport = $this->app()->container()->get(TransportFactory::class)->get('mail');
        $this->assertSame([], $transport->sent());

        // Proof the route queues a mail that goes out for a visitor (a fresh app: the audit's queued
        // mail stays queued, unsent, in the first one).
        $visitor = Application::create($this->dir);
        $visitor->boot();
        $visitor->handle((new Psr17Factory())->createServerRequest('GET', 'https://site.test/mail'));
        $this->assertCount(1, $visitor->container()->get(TransportFactory::class)->get('mail')->sent());
    }

    #[RunInSeparateProcess]
    public function test_booting_from_the_working_directory_leaves_no_session_file(): void
    {
        [$sessions, $code] = $this->bootWithSessionAtBoot(static fn (): int => (new SeoAuditCommand())->execute(['--url=/about']));

        $this->assertSame(0, $code);
        $this->assertSame([], $sessions);
    }

    #[RunInSeparateProcess]
    public function test_a_plain_boot_with_this_setup_writes_a_session_file(): void
    {
        // Proof for the test above: the same app, booted without the command, starts a file session.
        [$sessions] = $this->bootWithSessionAtBoot(function (): int {
            $this->app();

            return 0;
        });

        $this->assertCount(1, $sessions);
    }

    /**
     * Boot the app from its directory with a session started at boot (no session.paths) and files
     * kept in a directory of their own.
     *
     * @param \Closure(): int $boot
     * @return array{0: list<string>, 1: int} the session files, $boot's result
     */
    private function bootWithSessionAtBoot(\Closure $boot): array
    {
        file_put_contents($this->dir . '/config/auth.php', "<?php\nreturn ['session' => ['cookie' => 'seo_audit_test', 'secure' => false]];\n");
        @mkdir($this->dir . '/sessions', 0777, true);
        ini_set('session.save_path', $this->dir . '/sessions');
        $cwd = getcwd();
        chdir($this->dir);
        ob_start();
        try {
            $code = $boot();
        } finally {
            ob_end_clean();
            chdir((string) $cwd);
        }
        session_write_close();

        return [glob($this->dir . '/sessions/sess_*') ?: [], $code];
    }
}
