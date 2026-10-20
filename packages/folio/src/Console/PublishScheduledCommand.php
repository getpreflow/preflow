<?php

declare(strict_types=1);

namespace Preflow\Folio\Console;

use Preflow\Data\DataManager;
use Preflow\DevTools\Command\CommandInterface;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Publishing\Publisher;
use Preflow\Folio\Publishing\Scheduler;

/** Run from cron, e.g. every 5 minutes: cd /path/to/app && php preflow folio:publish-scheduled */
final class PublishScheduledCommand implements CommandInterface
{
    use BootsApplication;

    public function getName(): string { return 'folio:publish-scheduled'; }
    public function getDescription(): string { return 'Publish Folio records whose "Publish at" time is due'; }

    public function execute(array $args): int
    {
        $app = $this->app();
        $lockFile = $app->basePath('storage/locks/folio-publish-scheduled.lock');
        @mkdir(dirname($lockFile), 0775, true);
        $lock = fopen($lockFile, 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            echo "Already running.\n";
            return 0;
        }

        try {
            $c = $app->container();
            $result = (new Scheduler($c->get(Publisher::class), $c->get(TypeCatalog::class), $c->get(DataManager::class)))
                ->runDue(new \DateTimeImmutable());
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
