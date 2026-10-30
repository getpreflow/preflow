<?php

declare(strict_types=1);

namespace Preflow\I18n\Console;

use Preflow\Ai\Ai;
use Preflow\Core\Application;
use Preflow\DevTools\Command\CommandInterface;
use Preflow\I18n\Machine\TranslationWorker;

/**
 * preflow translate:work [--seconds=N]: works the queued machine-translation jobs for N seconds
 * (default 50). Meant for a cron job every minute, and cron mails whatever a job prints: it says
 * nothing when AI is off or there was nothing to do, only when records were worked or the run stopped.
 */
final class TranslateWorkCommand implements CommandInterface
{
    /** @param (\Closure(): Application)|null $appFactory returns a booted application (tests) */
    public function __construct(private readonly ?\Closure $appFactory = null) {}

    public function getName(): string { return 'translate:work'; }
    public function getDescription(): string { return 'Work the queued machine-translation jobs, for a cron job [--seconds=N]'; }

    public function execute(array $args): int
    {
        $seconds = 50;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--seconds=')) {
                $value = substr($arg, 10);
                if (preg_match('/^[1-9]\d*$/', $value) !== 1) {
                    fwrite(STDERR, "Usage: preflow translate:work [--seconds=N]\n");

                    return 1;
                }
                $seconds = (int) $value;
            }
        }

        $container = $this->app()->container();
        if (!$container->bound(TranslationWorker::class) || !$container->bound(Ai::class)) {
            fwrite(STDERR, "Machine translation is not set up: add Preflow\\I18n\\Machine\\MachineServiceProvider to config/providers.php.\n");

            return 1;
        }
        // Always run the worker: it writes the heartbeat even with AI off. Then there is nothing to say.
        $quiet = !$container->get(Ai::class)->enabled();

        $report = $container->get(TranslationWorker::class)->work($seconds, static function (string $line): void {
            echo $line, "\n";
        });
        if ($quiet || ($report->records === 0 && $report->stoppedBecause === null)) {
            return 0;
        }

        $cost = '$' . number_format($report->costUsd, 4);
        if ($report->costUnknown) {
            $cost .= ' (some prices unknown)';
        }
        echo "{$report->records} records, {$cost}\n";
        if ($report->stoppedBecause !== null) {
            echo "Stopped: {$report->stoppedBecause}\n";
        }

        return 0;
    }

    private function app(): Application
    {
        if ($this->appFactory !== null) {
            return ($this->appFactory)();
        }

        $app = Application::create(getcwd() ?: '.');
        $app->boot();

        return $app;
    }
}
