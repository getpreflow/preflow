<?php

declare(strict_types=1);

namespace Preflow\Media\Console;

use Preflow\Core\Application;
use Preflow\DevTools\Command\CommandInterface;
use Preflow\Media\MediaLibrary;

/**
 * Delete earlier files of replaced assets: media:versions:prune [--keep=N]
 * [--older-than=DAYS] [--id=ID]. Keeps the N newest earlier versions of each asset
 * (0 without --keep); with --older-than only those replaced more than DAYS ago go.
 * The current file is never touched. One of --keep or --older-than is required, so
 * a bare run deletes nothing.
 */
final class VersionsPruneCommand implements CommandInterface
{
    /** @param (\Closure(): Application)|null $appFactory returns a booted application (tests) */
    public function __construct(
        private readonly ?\Closure $appFactory = null,
    ) {}

    public function getName(): string { return 'media:versions:prune'; }
    public function getDescription(): string { return 'Delete earlier files of replaced assets [--keep=N] [--older-than=DAYS] [--id=<id>]'; }

    public function execute(array $args): int
    {
        $keep = $olderThan = $id = null;
        foreach ($args as $arg) {
            if (preg_match('/^--keep=(\d+)$/', $arg, $m) === 1) {
                $keep = (int) $m[1];
            } elseif (preg_match('/^--older-than=(\d+)$/', $arg, $m) === 1) {
                $olderThan = (int) $m[1];
            } elseif (str_starts_with($arg, '--id=')) {
                $id = substr($arg, 5);
            }
        }
        if ($keep === null && $olderThan === null) {
            echo "Usage: media:versions:prune [--keep=N] [--older-than=DAYS] [--id=<id>] (give --keep, --older-than or both).\n";
            return 1;
        }

        $library = $this->app()->container()->get(MediaLibrary::class);
        $assets = $id !== null ? array_filter([$library->find($id)]) : $library->all();
        $deleted = 0;
        foreach ($assets as $asset) {
            $deleted += $library->prune($asset->id, $keep ?? 0, $olderThan);
        }
        echo "Deleted {$deleted} version" . ($deleted === 1 ? '' : 's') . ".\n";

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
