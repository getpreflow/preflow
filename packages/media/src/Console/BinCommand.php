<?php

declare(strict_types=1);

namespace Preflow\Media\Console;

use Preflow\Core\Application;
use Preflow\DevTools\Command\CommandInterface;
use Preflow\Media\Asset;
use Preflow\Media\MediaConfig;
use Preflow\Media\MediaLibrary;

/**
 * The bin of deleted assets: media:bin lists it (newest binned first; --limit=<n> and
 * --offset=<n> in chunks), --restore=<id> brings one back, --empty deletes for good what
 * has been in the bin longer than config/media.php cleanup.bin_days (30), except what a
 * usage source still reports (also in chunks: --limit=<n> checks at most n, the longest
 * binned first). Needs bin_dir. The package lists it in composer.json
 * (extra.preflow.commands), so a site needs no setup.
 */
final class BinCommand implements CommandInterface
{
    use ChunkOptions;

    /** Assets read per page when the list has no --limit. */
    private const int PAGE = 100;

    /**
     * @param (\Closure(): Application)|null $appFactory returns a booted application (tests)
     * @param resource|null                  $stderr     error stream (default STDERR)
     */
    public function __construct(
        private readonly ?\Closure $appFactory = null,
        private readonly mixed $stderr = null,
    ) {}

    public function getName(): string { return 'media:bin'; }
    public function getDescription(): string { return 'List deleted assets [--limit=<n> --offset=<n>], restore one [--restore=<id>], or delete for good after cleanup.bin_days [--empty --limit=<n> --offset=<n>]'; }

    public function execute(array $args): int
    {
        $restore = self::option($args, 'restore');
        try {
            $limit = self::number($args, 'limit', 1);
            $offset = self::number($args, 'offset', 0) ?? 0;
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }

        $app = $this->app();
        $library = $app->container()->get(MediaLibrary::class);
        if ($library->binDir() === null) {
            return $this->fail("bin_dir is not set in config/media.php, so there is no bin: deleted assets are removed for good.\n");
        }
        if ($restore !== null) {
            return $this->restore($library, $restore);
        }
        if (in_array('--empty', $args, true)) {
            return $this->empty($library, $app->container()->get(MediaConfig::class)->cleanup['bin_days'], $limit, $offset);
        }

        return $this->list($library, $limit, $offset);
    }

    private function list(MediaLibrary $library, ?int $limit, int $offset): int
    {
        // Read page by page (never the whole bin at once); an offset between pages reads two.
        $per = $limit ?? self::PAGE;
        $page = intdiv($offset, $per) + 1;
        $skip = $offset % $per;
        $current = $library->binned($page, $per);
        $total = $current->total;
        if ($total === 0) {
            echo "The bin is empty.\n";

            return 0;
        }
        echo self::plural($total, 'asset') . " in the bin:\n";
        $shown = 0;
        while ($current->page === $page) {
            foreach (array_slice($current->items, $skip) as $asset) {
                if ($limit !== null && $shown >= $limit) {
                    break 2;
                }
                $at = \DateTimeImmutable::createFromFormat(DATE_ATOM, (string) $asset->binnedAt);
                echo "{$asset->id}  {$asset->kind()}  " . ($at !== false ? $at->format('Y-m-d H:i') : (string) $asset->binnedAt) . "  {$asset->label()}\n";
                $shown++;
            }
            if (($limit !== null && $shown >= $limit) || $page * $per >= $total) {
                break;
            }
            $skip = 0;
            $current = $library->binned(++$page, $per);
        }
        $left = max(0, $total - $offset - $shown);
        if ($left > 0) {
            echo self::plural($left, 'asset') . " left: run again with --limit={$limit} --offset=" . ($offset + $shown) . ".\n";
        }

        return 0;
    }

    private function restore(MediaLibrary $library, string $id): int
    {
        try {
            $asset = $library->restoreFromBin($id);
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            return $this->fail($e->getMessage() . "\n");
        }
        echo "Restored {$asset->label()} ({$asset->id}).\n";

        return 0;
    }

    private function empty(MediaLibrary $library, int $days, ?int $limit, int $offset): int
    {
        $labels = [];
        $result = $library->emptyBin($days, static function (Asset $asset) use (&$labels): void {
            $labels[$asset->id] = $asset->label();
        }, $limit, $offset);
        echo 'Deleted ' . self::plural($result->deleted, 'asset') . " for good (in the bin longer than {$days} days).\n";
        if ($result->kept !== []) {
            echo 'Kept ' . self::plural(count($result->kept), 'asset') . ":\n";
            foreach ($result->kept as $kept) {
                echo "  {$kept['id']} " . ($labels[$kept['id']] ?? '') . ": {$kept['reason']}\n";
            }
        }
        if ($result->left > 0) {
            // Deleted assets left the bin; the kept ones stay ahead of the next chunk.
            $next = $offset + count($result->kept);
            echo self::plural($result->left, 'asset') . " left: run again with --empty --limit={$limit}" . ($next > 0 ? " --offset={$next}" : '') . ".\n";
        }

        return 0;
    }

    private function fail(string $message): int
    {
        fwrite($this->stderr ?? STDERR, $message);

        return 1;
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
