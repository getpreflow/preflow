<?php

declare(strict_types=1);

namespace Preflow\Media\Console;

use Preflow\Core\Application;
use Preflow\DevTools\Command\CommandInterface;
use Preflow\Media\MediaLibrary;
use Preflow\Media\Preview\PreviewRenderers;

/**
 * Make document previews (a PDF's first page), e.g. for documents uploaded before
 * previews existed: media:previews [--id=<id>] [--all] [--limit=<n>]. Without options,
 * every document without a preview (so a run that was stopped resumes where it ended);
 * --all makes every document's preview again; --id that one's; --limit=<n> does at
 * most n and prints the command that continues (--offset=<n> skips the first n). Private documents get private previews, public
 * ones also their variants. The package lists it in composer.json
 * (extra.preflow.commands), so a site needs no setup.
 */
final class PreviewsCommand implements CommandInterface
{
    use ChunkOptions;

    /**
     * @param (\Closure(): Application)|null $appFactory returns a booted application (tests)
     * @param resource|null                  $stderr     error stream (default STDERR)
     */
    public function __construct(
        private readonly ?\Closure $appFactory = null,
        private readonly mixed $stderr = null,
    ) {}

    public function getName(): string { return 'media:previews'; }
    public function getDescription(): string { return 'Make previews of library documents without one [--id=<id>] [--all: make every preview again] [--limit=<n> --offset=<n>: in chunks]'; }

    public function execute(array $args): int
    {
        $id = self::option($args, 'id');
        try {
            $limit = self::number($args, 'limit', 1);
            $offset = self::number($args, 'offset', 0) ?? 0;
        } catch (\InvalidArgumentException $e) {
            fwrite($this->stderr ?? STDERR, $e->getMessage());

            return 1;
        }
        $all = in_array('--all', $args, true) || $id !== null;

        $app = $this->app();
        $library = $app->container()->get(MediaLibrary::class);
        $renderers = $app->container()->get(PreviewRenderers::class);
        $assets = $id !== null ? array_filter([$library->find($id)]) : $library->all();
        if ($id !== null && $assets === []) {
            fwrite($this->stderr ?? STDERR, "Asset {$id} not found.\n");

            return 1;
        }
        $todo = [];
        $unsupported = 0;
        foreach ($assets as $asset) {
            if ($asset->kind() !== 'document' || (!$all && $asset->preview !== null)) {
                continue;
            }
            if ($renderers->for($asset->mime) === null) {
                $unsupported++;
                continue;
            }
            $todo[] = $asset;
        }
        $chunk = array_slice($todo, $offset, $limit);
        $left = max(0, count($todo) - $offset - count($chunk));
        $made = 0;
        $failed = 0;
        foreach ($chunk as $asset) {
            $library->remakePreview($asset) !== null ? $made++ : $failed++;
        }
        echo 'Made previews of ' . self::plural($made, 'document') . ".\n";
        if ($failed > 0) {
            echo self::plural($failed, 'document') . " could not be rendered (unreadable, encrypted or over the time limit).\n";
        }
        if ($unsupported > 0) {
            echo "{$unsupported} document" . ($unsupported === 1 ? ' has' : 's have') . " no preview renderer (PDFs need Ghostscript or pdftocairo).\n";
        }
        if ($left > 0) {
            // Without --all, documents that got a preview drop out of the list; only failures stay ahead.
            $next = $offset + ($all ? count($chunk) : $failed);
            echo self::plural($left, 'document') . ' left: run again with ' . ($all ? '--all ' : '') . "--limit={$limit}" . ($next > 0 ? " --offset={$next}" : '') . ".\n";
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
