<?php

declare(strict_types=1);

namespace Preflow\Folio\Console;

use Preflow\Core\Application;
use Preflow\DevTools\Command\CommandInterface;
use Preflow\Publishing\Console\PublishScheduledCommand as PublishScheduled;

/**
 * @deprecated since 0.71, use "publish:scheduled" (preflow/publishing) in cron.
 * folio:publish-scheduled still works for one release: it prints a note and runs publish:scheduled.
 */
final class PublishScheduledCommand implements CommandInterface
{
    use BootsApplication;

    public function getName(): string { return 'folio:publish-scheduled'; }
    public function getDescription(): string { return 'Deprecated: use publish:scheduled'; }

    public function execute(array $args): int
    {
        echo "Note: \"folio:publish-scheduled\" is deprecated; use \"publish:scheduled\".\n";

        return (new PublishScheduled(fn (): Application => $this->app()))->execute($args);
    }
}
