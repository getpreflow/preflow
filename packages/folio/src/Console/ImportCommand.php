<?php

declare(strict_types=1);

namespace Preflow\Folio\Console;

use Preflow\Data\DataManager;
use Preflow\DevTools\Command\CommandInterface;
use Preflow\Folio\Transfer\Backups;
use Preflow\Folio\Transfer\ContentSet;
use Preflow\Folio\Transfer\Importer;
use Preflow\Folio\Transfer\TransferException;

final class ImportCommand extends TransferCommand implements CommandInterface
{
    public function getName(): string
    {
        return 'folio:import';
    }

    public function getDescription(): string
    {
        return 'Replace Folio content with an export (writes a backup first) <file>[.gz] [--dry-run] [--only=data|media]';
    }

    public function execute(array $args): int
    {
        $only = self::only($args);
        $file = self::positional($args)[0] ?? null;
        if ($only === null || $file === null) {
            $this->warn('Usage: folio:import <file>[.gz] [--dry-run] [--only=data|media]');

            return 1;
        }

        try {
            $app = $this->app();
            $importer = new Importer(ContentSet::fromApp($app), $app->container()->get(DataManager::class));
            $in = Backups::open($file);
            try {
                $plan = $importer->read($in, $only);
            } finally {
                fclose($in);
            }
        } catch (TransferException $e) {
            $this->warn('Import refused: ' . $e->getMessage());

            return 1;
        }

        return self::applyPlan($this, $app, $importer, $plan, in_array('--dry-run', $args, true), 'import');
    }

    /** Shared by folio:import and folio:pull: show, back up, apply. */
    public static function applyPlan(TransferCommand $cmd, \Preflow\Core\Application $app, Importer $importer, \Preflow\Folio\Transfer\ImportPlan $plan, bool $dryRun, string $backupPrefix): int
    {
        try {
            foreach ($plan->notices as $notice) {
                $cmd->say('Note: ' . $notice);
            }
            if ($plan->isEmpty()) {
                $cmd->say('Nothing to change: local content already matches.');

                return 0;
            }
            $cmd->say('Changes (+added ~changed -removed):');
            foreach ($plan->lines() as $line) {
                $cmd->say('  ' . $line);
            }
            if ($dryRun) {
                $cmd->say('Dry run: nothing was changed.');

                return 0;
            }
            try {
                $backup = Backups::write($app, $backupPrefix);
            } catch (\Throwable $e) {
                $cmd->warn('Backup failed (' . $e->getMessage() . '); nothing was changed.');

                return 1;
            }
            $cmd->say('Backup: ' . $backup);
            try {
                $importer->apply($plan);
            } catch (\Throwable $e) {
                if ($e instanceof TransferException && $e->getCode() === Importer::RECORDS_UNCHANGED) {
                    $cmd->warn('Import failed, nothing was changed: ' . $e->getMessage());

                    return 1;
                }
                $cmd->warn('Import stopped part-way: ' . $e->getMessage());
                $cmd->warn('Local content is now mixed. Restore it with: php preflow folio:import ' . $backup);

                return 1;
            }
            $cmd->say('Done. Run "php preflow cache:clear --all" if pages look stale.');

            return 0;
        } finally {
            $plan->cleanup();
        }
    }
}
