<?php

declare(strict_types=1);

namespace Preflow\Folio\Console;

use Preflow\Core\Application;
use Preflow\DevTools\Command\CommandInterface;
use Preflow\Publishing\Console\PublishCommand as Publish;

/**
 * @deprecated since 0.71, use "publish" (preflow/publishing).
 * folio:publish still works for one release: it prints a note and runs publish (which skips the
 * legacy drafts through Folio's PublishSkips, as folio:publish did).
 */
final class PublishCommand implements CommandInterface
{
    use BootsApplication;

    public function getName(): string { return 'folio:publish'; }
    public function getDescription(): string { return 'Deprecated: use publish'; }

    public function execute(array $args): int
    {
        echo "Note: \"folio:publish\" is deprecated; use \"publish\".\n";

        return (new Publish(fn (): Application => $this->app()))->execute($args);
    }
}
