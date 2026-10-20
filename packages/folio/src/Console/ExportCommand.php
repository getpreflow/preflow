<?php

declare(strict_types=1);

namespace Preflow\Folio\Console;

use Preflow\Data\DataManager;
use Preflow\DevTools\Command\CommandInterface;
use Preflow\Folio\Transfer\ContentSet;
use Preflow\Folio\Transfer\Exporter;

final class ExportCommand extends TransferCommand implements CommandInterface
{
    public function getName(): string
    {
        return 'folio:export';
    }

    public function getDescription(): string
    {
        return 'Export Folio content (records and media) [<file>[.gz] | --stdout] [--only=data|media]';
    }

    public function execute(array $args): int
    {
        $only = self::only($args);
        $file = self::positional($args)[0] ?? null;
        if ($only === null || ($file === null && !in_array('--stdout', $args, true))) {
            $this->warn('Usage: folio:export <file>[.gz] | --stdout [--only=data|media]');

            return 1;
        }

        if ($file === null) {
            // The archive owns stdout: PHP notices (CLI prints them to stdout by default) go to stderr.
            ini_set('display_errors', 'stderr');
        }
        $app = $this->app();
        $out = $file === null ? $this->stdout : fopen((str_ends_with($file, '.gz') ? 'compress.zlib://' : '') . $file, 'wb');
        if ($out === false) {
            $this->warn("Cannot write {$file}");

            return 1;
        }
        try {
            $summary = (new Exporter(ContentSet::fromApp($app), $app->container()->get(DataManager::class)))->export($out, $only);
        } catch (\Preflow\Folio\Transfer\TransferException $e) {
            if ($file !== null) {
                fclose($out);
                @unlink($file); // never leave a partial archive that looks complete
                $file = null;
            }
            $this->warn('Export failed: ' . $e->getMessage());

            return 1;
        } finally {
            if ($file !== null) {
                fclose($out);
            }
        }

        $this->warn(sprintf(
            'Exported %d records in %d tables, %d media and %d upload files (%s KB).',
            array_sum($summary['tables']),
            count(array_filter($summary['tables'])),
            $summary['media'],
            $summary['uploads'],
            number_format($summary['bytes'] / 1024, 0, '.', "'"),
        ));

        return 0;
    }
}
