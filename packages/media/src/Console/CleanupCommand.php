<?php

declare(strict_types=1);

namespace Preflow\Media\Console;

use Preflow\Core\Application;
use Preflow\DevTools\Command\CommandInterface;
use Preflow\Media\Cleanup\AssetCleanup;
use Preflow\Media\MediaConfig;
use Preflow\Media\MediaLibrary;
use Preflow\Media\Usage\AssetInUseException;

/**
 * Unused assets: media:cleanup lists them (oldest upload first, with why each counts as
 * unused and which usage sources were asked); --delete moves them into the bin, never
 * deletes them for good (so it needs bin_dir; media:bin --restore=<id> brings one back).
 * Filters: --kind=image|video|document, --by=<uploader>, --private or --public,
 * --older-than=<days> (default cleanup.min_age_days). --limit=<n> checks at most n assets
 * and prints the command that continues (--offset=<n> skips the first n). Refuses while
 * config/media.php cleanup.enabled is off: cleanup only knows the usages that usage sources
 * report. The package lists it in composer.json (extra.preflow.commands).
 */
final class CleanupCommand implements CommandInterface
{
    use ChunkOptions;

    public const string OFF = 'Cleanup is off (config/media.php cleanup.enabled). Read the media README first: cleanup only knows usages reported by usage sources.';

    /**
     * @param (\Closure(): Application)|null $appFactory returns a booted application (tests)
     * @param resource|null                  $stderr     error stream (default STDERR)
     */
    public function __construct(
        private readonly ?\Closure $appFactory = null,
        private readonly mixed $stderr = null,
    ) {}

    public function getName(): string { return 'media:cleanup'; }
    public function getDescription(): string { return 'List unused assets, or move them into the bin [--delete]; [--kind=<kind> --by=<user> --private|--public --older-than=<days>] [--limit=<n> --offset=<n>]'; }

    public function execute(array $args): int
    {
        try {
            $limit = self::number($args, 'limit', 1);
            $offset = self::number($args, 'offset', 0) ?? 0;
            $olderThan = self::number($args, 'older-than', 0);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
        $private = in_array('--private', $args, true);
        $public = in_array('--public', $args, true);
        if ($private && $public) {
            return $this->fail("Use --private or --public, not both.\n");
        }
        $delete = in_array('--delete', $args, true);
        $filters = array_filter([
            'kind' => self::option($args, 'kind'),
            'by' => self::option($args, 'by'),
            'private' => $private ? true : ($public ? false : null),
            'older_than_days' => $olderThan,
        ], static fn (mixed $v): bool => $v !== null);

        $container = $this->app()->container();
        $cleanup = $container->get(AssetCleanup::class);
        if (!$cleanup->isEnabled()) {
            return $this->fail(self::OFF . "\n");
        }
        $library = $container->get(MediaLibrary::class);
        if ($delete && $library->binDir() === null) {
            return $this->fail("bin_dir is not set in config/media.php: cleanup only moves assets into the bin and never deletes them for good.\n");
        }
        try {
            $selected = $cleanup->selected($filters);
            $candidates = $cleanup->candidates($filters, $offset, $limit);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage() . "\n");
        }
        $checked = max(0, min($limit ?? PHP_INT_MAX, $selected - $offset));
        $days = $olderThan ?? $container->get(MediaConfig::class)->cleanup['min_age_days'];

        echo 'Checked ' . self::plural($checked, 'asset') . " uploaded more than {$days} days ago: " . count($candidates) . " unused.\n";
        foreach ($candidates as $candidate) {
            $asset = $candidate->asset;
            echo "{$asset->id}  {$asset->kind()}  " . substr($asset->createdAt, 0, 10) . "  {$asset->label()}: {$candidate->reason}\n";
        }
        $moved = 0;
        if ($delete) {
            $failed = [];
            foreach ($candidates as $candidate) {
                try {
                    $library->delete($candidate->asset->id);
                    $moved++;
                } catch (AssetInUseException|\RuntimeException $e) {
                    $failed[] = "  {$candidate->asset->id} {$candidate->asset->label()}: {$e->getMessage()}";
                }
            }
            echo 'Moved ' . self::plural($moved, 'asset') . " into the bin (media:bin --restore=<id> brings one back).\n";
            if ($failed !== []) {
                echo 'Could not move ' . self::plural(count($failed), 'asset') . ":\n" . implode("\n", $failed) . "\n";
            }
        } elseif ($candidates !== []) {
            echo "Nothing was moved: run again with --delete to move them into the bin.\n";
        }
        $left = max(0, $selected - $offset - $checked);
        if ($left > 0) {
            // Binned assets leave the selection: the next chunk starts after what stayed.
            $next = $offset + $checked - $moved;
            $same = array_filter($args, static fn (string $a): bool => !str_starts_with($a, '--limit=') && !str_starts_with($a, '--offset='));
            echo self::plural($left, 'asset') . ' left to check: run again with ' . implode(' ', [...$same, "--limit={$limit}"]) . ($next > 0 ? " --offset={$next}" : '') . ".\n";
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
