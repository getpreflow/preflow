<?php

declare(strict_types=1);

namespace Preflow\View\Console;

use Preflow\Core\Application;
use Preflow\DevTools\Command\CommandInterface;
use Preflow\View\Audit\AuditReport;
use Preflow\View\Audit\Audits;
use Preflow\View\Audit\AuditRunning;
use Preflow\View\Audit\Finding;
use Preflow\View\Audit\PageRenderer;
use Preflow\View\Audit\Severity;

/**
 * preflow seo:audit [--fresh] [--limit=N] [--url=/path] [--budget=600]: audits the
 * site's public pages inside the app (no network), prints the progress, the counts
 * per severity and the findings grouped by severity, then check. A full run saves
 * its report (storage/data/seo-audit.json, shown in Folio) and stops before the
 * time budget; running it again continues. --limit and --url are quick checks that
 * print only and leave the saved report alone.
 *
 * Exit code 0 for a run with or without findings, finished or not, and when another
 * audit is running; 1 for a bad flag, an app that doesn't boot, or a run that fails.
 * The app is booted without a session store, so the audit leaves no session file,
 * and mail queued while a page renders isn't sent (the internal render scope drops
 * it). The run goes through the Audits service (since 0.74), as Folio's does. The package lists it in
 * composer.json (extra.preflow.commands), so a site needs no setup.
 *
 * @since 0.54
 */
final class SeoAuditCommand implements CommandInterface
{
    private const DEFAULT_BUDGET = 600;

    private const HELP = <<<'TXT'
        Usage: preflow seo:audit [--fresh] [--limit=N] [--url=/path] [--budget=600]

        Audits the site's public pages (every locale) inside the app and prints what it finds.
        A full run saves its report in storage/data/seo-audit.json (Folio shows it) and stops
        before the time budget; run it again to continue where it stopped.

          --fresh        start over, even when the last run can be continued
          --limit=N      quick check of the first N public pages; prints only, the saved report is left alone
          --url=/path    quick check of one page (without the site checks); prints only
          --budget=600   stop before this many seconds (default 600)

        Exit code 0 when the audit ran (with or without findings, finished or not).
        Another audit is running: the command says so and exits 0 without auditing.
        Exit code 1 for a bad flag, an app that doesn't boot, or a run that fails.

        TXT;

    /**
     * @param (\Closure(): Application)|null $appFactory returns a booted application (tests)
     * @param resource|null                  $stderr     error stream (default STDERR)
     * @param bool|null                      $tty        whether the output is a terminal (default: STDOUT's)
     */
    public function __construct(
        private readonly ?\Closure $appFactory = null,
        private readonly mixed $stderr = null,
        private readonly ?bool $tty = null,
    ) {}

    public function getName(): string { return 'seo:audit'; }
    public function getDescription(): string { return 'Audit the public pages for SEO problems [--fresh] [--limit=N] [--url=/path] [--budget=600] (--help)'; }

    public function execute(array $args): int
    {
        if (in_array('--help', $args, true)) {
            echo self::HELP;

            return 0;
        }

        try {
            $options = self::options($args);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage() . "\nRun `preflow seo:audit --help` for the flags.\n");

            return 1;
        }

        try {
            $app = $this->app();
            $container = $app->container();
            if (!$container->bound(PageRenderer::class)) {
                throw new \RuntimeException('The app has no SEO audit page renderer (preflow/view is not set up).');
            }
            $audits = $container->get(Audits::class);
        } catch (\Throwable $e) {
            $this->error('Error: the app could not be booted: ' . $e->getMessage() . "\n");

            return 1;
        }

        $progress = $this->progress();
        try {
            $report = $options['limit'] !== null || $options['url'] !== null
                ? $audits->quick($options['limit'], $options['url'], $options['budget'], $progress->report(...))
                : $audits->step($options['budget'], $options['fresh'], $progress->report(...));
        } catch (AuditRunning $e) {
            echo $e->getMessage() . "\n";

            return 0;
        } catch (\Throwable $e) {
            $progress->end();
            $this->error('Error: the audit failed: ' . $e->getMessage() . "\n");

            return 1;
        }
        $progress->end();

        $this->print($report, quick: $options['limit'] !== null || $options['url'] !== null);

        return 0;
    }

    /**
     * The flags; a bad one throws.
     *
     * @param list<string> $args
     * @return array{fresh: bool, limit: ?int, url: ?string, budget: int}
     */
    private static function options(array $args): array
    {
        $options = ['fresh' => false, 'limit' => null, 'url' => null, 'budget' => self::DEFAULT_BUDGET];
        foreach ($args as $arg) {
            if ($arg === '--fresh') {
                $options['fresh'] = true;
            } elseif (str_starts_with($arg, '--limit=')) {
                $options['limit'] = self::number($arg, '--limit=');
            } elseif (str_starts_with($arg, '--budget=')) {
                $options['budget'] = self::number($arg, '--budget=');
            } elseif (str_starts_with($arg, '--url=')) {
                $url = substr($arg, strlen('--url='));
                if (trim($url) === '') {
                    throw new \InvalidArgumentException('--url needs a page, e.g. --url=/about.');
                }
                $options['url'] = $url;
            } else {
                throw new \InvalidArgumentException("Unknown argument: {$arg}");
            }
        }
        if ($options['limit'] !== null && $options['url'] !== null) {
            throw new \InvalidArgumentException('Use --limit or --url, not both.');
        }

        return $options;
    }

    private static function number(string $arg, string $prefix): int
    {
        $value = substr($arg, strlen($prefix));
        if (preg_match('/^[1-9]\d{0,8}$/', $value) !== 1) {
            throw new \InvalidArgumentException(rtrim($prefix, '=') . ' needs a whole number of at least 1, got "' . $value . '".');
        }

        return (int) $value;
    }

    /** Progress as "done/total": one line rewritten on a terminal, otherwise a line about every tenth. */
    private function progress(): object
    {
        $tty = $this->tty ?? (function_exists('stream_isatty') && stream_isatty(STDOUT));

        return new class ($tty) {
            private ?int $step = null;

            private bool $open = false;

            public function __construct(private readonly bool $tty) {}

            public function report(int $done, int $total, string $url): void
            {
                if ($this->tty) {
                    echo "\r{$done}/{$total}";
                    $this->open = true;

                    return;
                }
                $step = intdiv($done * 10, max(1, $total));
                if ($this->step === null || $step > $this->step || $done >= $total) {
                    echo "{$done}/{$total}\n";
                }
                $this->step = $step;
            }

            /** Ends the terminal's progress line. */
            public function end(): void
            {
                if ($this->open) {
                    echo "\n";
                    $this->open = false;
                }
            }
        };
    }

    private function print(AuditReport $report, bool $quick): void
    {
        if ($report->total === 0) {
            echo "No public pages to audit.\n";
        }

        $counts = $report->counts();
        echo "\n";
        printf("Critical: %d\nWarning:  %d\nNotice:   %d\n", $counts['critical'], $counts['warning'], $counts['notice']);

        foreach (Severity::cases() as $severity) {
            $byCheck = [];
            foreach ($report->findings as $finding) {
                if ($finding->severity === $severity) {
                    $byCheck[$finding->check][] = $finding;
                }
            }
            if ($byCheck === []) {
                continue;
            }
            ksort($byCheck, SORT_STRING);
            echo "\n" . ucfirst($severity->value) . "\n";
            foreach ($byCheck as $check => $findings) {
                echo "  {$check}\n";
                foreach ($findings as $finding) {
                    echo '    ' . self::where($finding) . ': ' . $finding->message . "\n";
                }
            }
        }

        echo "\n";
        $pages = "{$report->done()} of {$report->total} " . ($report->total === 1 ? 'page' : 'pages');
        if ($report->complete()) {
            echo $quick
                ? "Complete ({$pages}). Quick check: the saved report was left alone.\n"
                : "Complete ({$pages}). Report saved to " . AuditReport::PATH . ".\n";
        } elseif ($quick) {
            echo "Stopped at the time budget ({$pages}). A quick check doesn't continue: give it a bigger --budget. The saved report was left alone.\n";
        } else {
            echo "Incomplete ({$pages}). Run `preflow seo:audit` again to continue.\n";
        }
    }

    /** The finding's page and locale, or "site" for a site-wide one. */
    private static function where(Finding $finding): string
    {
        $where = $finding->url !== '' ? $finding->url : 'site';

        return $finding->locale !== '' ? "{$where} ({$finding->locale})" : $where;
    }

    private function error(string $text): void
    {
        fwrite($this->stderr ?? STDERR, $text);
    }

    private function app(): Application
    {
        self::withoutSessionStorage();
        if ($this->appFactory !== null) {
            return ($this->appFactory)();
        }

        $app = Application::create(getcwd() ?: '.');
        $app->boot();

        return $app;
    }

    /**
     * A site without session.paths starts its session at boot, which in the CLI would leave a
     * session file behind each run. Before the boot, this process's sessions get a store that
     * keeps nothing. (Audit renders run without a session anyway.)
     */
    private static function withoutSessionStorage(): void
    {
        if (session_status() !== PHP_SESSION_NONE || headers_sent()) {
            return;
        }

        session_set_save_handler(new class implements \SessionHandlerInterface {
            public function open(string $path, string $name): bool { return true; }
            public function close(): bool { return true; }
            public function read(string $id): string|false { return ''; }
            public function write(string $id, string $data): bool { return true; }
            public function destroy(string $id): bool { return true; }
            public function gc(int $max_lifetime): int|false { return 0; }
        }, false);
    }
}
