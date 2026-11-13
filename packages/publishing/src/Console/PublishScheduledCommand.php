<?php

declare(strict_types=1);

namespace Preflow\Publishing\Console;

use Preflow\DevTools\Command\CommandInterface;
use Preflow\Publishing\Scheduler;

/**
 * publish:scheduled publishes the records whose "Publish at" is due. Run it from cron, e.g. every
 * 5 minutes: cd /path/to/app && php preflow publish:scheduled. A run while another one is still
 * going does nothing. The package lists it in composer.json (extra.preflow.commands).
 */
final class PublishScheduledCommand implements CommandInterface
{
    use BootsApplication;

    public function getName(): string { return 'publish:scheduled'; }
    public function getDescription(): string { return 'Publish the records whose "Publish at" time is due'; }

    public function execute(array $args): int
    {
        $app = $this->app();
        $lockFile = $app->basePath('storage/locks/publish-scheduled.lock');
        @mkdir(dirname($lockFile), 0775, true);
        $lock = fopen($lockFile, 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            echo "Already running.\n";
            return 0;
        }

        try {
            $result = $app->container()->get(Scheduler::class)->runDue(new \DateTimeImmutable());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        foreach ($result['published'] as $key) {
            echo "Published {$key}\n";
        }
        foreach ($result['failed'] as $key => $reason) {
            echo "Failed {$key}: {$reason}\n";
        }
        if ($result['published'] === [] && $result['failed'] === []) {
            echo "Nothing due.\n";
        }

        return $result['failed'] === [] ? 0 : 1;
    }
}
