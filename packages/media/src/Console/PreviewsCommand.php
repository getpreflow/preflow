<?php

declare(strict_types=1);

namespace Preflow\Media\Console;

use Preflow\Core\Application;
use Preflow\DevTools\Command\CommandInterface;
use Preflow\Media\MediaLibrary;
use Preflow\Media\Preview\PreviewRenderers;

/**
 * Make document previews (a PDF's first page), e.g. for documents uploaded before
 * previews existed: media:previews [--id=<id>] [--all]. Without options, every
 * document without a preview; --all makes every document's preview again; --id that
 * one's. Private documents get private previews, public ones also their variants.
 * The package lists it in composer.json (extra.preflow.commands), so a site needs no setup.
 */
final class PreviewsCommand implements CommandInterface
{
    /** @param (\Closure(): Application)|null $appFactory returns a booted application (tests) */
    public function __construct(
        private readonly ?\Closure $appFactory = null,
    ) {}

    public function getName(): string { return 'media:previews'; }
    public function getDescription(): string { return 'Make previews of library documents without one [--id=<id>] [--all: make every preview again]'; }

    public function execute(array $args): int
    {
        $id = null;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--id=')) {
                $id = substr($arg, 5);
            }
        }
        $all = in_array('--all', $args, true) || $id !== null;

        $app = $this->app();
        $library = $app->container()->get(MediaLibrary::class);
        $renderers = $app->container()->get(PreviewRenderers::class);
        $assets = $id !== null ? array_filter([$library->find($id)]) : $library->all();
        $made = 0;
        $failed = 0;
        $unsupported = 0;
        foreach ($assets as $asset) {
            if ($asset->kind() !== 'document' || (!$all && $asset->preview !== null)) {
                continue;
            }
            if ($renderers->for($asset->mime) === null) {
                $unsupported++;
                continue;
            }
            $library->remakePreview($asset)->preview !== null ? $made++ : $failed++;
        }
        echo "Made previews of {$made} document" . ($made === 1 ? '' : 's') . ".\n";
        if ($failed > 0) {
            echo "{$failed} document" . ($failed === 1 ? '' : 's') . " could not be rendered (unreadable, encrypted or over the time limit).\n";
        }
        if ($unsupported > 0) {
            echo "{$unsupported} document" . ($unsupported === 1 ? ' has' : 's have') . " no preview renderer (PDFs need Ghostscript or pdftocairo).\n";
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
