<?php

declare(strict_types=1);

namespace Preflow\Publishing\Console;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\DevTools\Command\CommandInterface;
use Preflow\Publishing\Publisher;
use Preflow\Publishing\PublishSkips;
use Preflow\Publishing\PublishState;
use Preflow\Publishing\Scheduler;
use Preflow\Validation\ValidationException;

/**
 * publish <type> publishes the drafts of a type, e.g. after turning "publishing" on for a type
 * with content. By default only records that have never been published; --all publishes every
 * working copy, unfinished edits included; --id=<id> only that record. A never-published record
 * that PublishSkips gives a reason for is skipped too without --all. The package lists it in
 * composer.json (extra.preflow.commands).
 */
final class PublishCommand implements CommandInterface
{
    use BootsApplication;

    public function getName(): string { return 'publish'; }
    public function getDescription(): string { return 'Publish the drafts of a type (<type> [--id=<id>] [--all])'; }

    public function execute(array $args): int
    {
        $type = '';
        $id = null;
        $all = false;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--id=')) {
                $id = substr($arg, 5);
            } elseif ($arg === '--all') {
                $all = true;
            } elseif (!str_starts_with($arg, '--')) {
                $type = $arg;
            }
        }

        $c = $this->app()->container();
        $publisher = $c->get(Publisher::class);
        if ($type === '' || !$publisher->enabled($type)) {
            echo "Usage: preflow publish <type> [--id=<id>] [--all] — \"{$type}\" is not a publishing type.\n";
            return 1;
        }

        $dm = $c->get(DataManager::class);
        $records = $id !== null
            ? array_filter([$dm->findType($type, $id)])
            : $dm->queryType($type)->get()->items();
        $skips = $c->get(PublishSkips::class);

        $failed = false;
        foreach ($records as $record) {
            $key = $type . '/' . $record->getId();
            $reason = $all ? null : $this->skipReason($publisher, $record, $skips);
            if ($reason !== null) {
                echo "Skipped {$key} ({$reason}; use --all to publish anyway)\n";
                continue;
            }
            try {
                $publisher->publish($record, 'cli');
                echo "Published {$key}\n";
            } catch (ValidationException $e) {
                $failed = true;
                echo "Failed {$key}: " . Scheduler::firstMessage($e) . "\n";
            }
        }

        return $failed ? 1 : 0;
    }

    private function skipReason(Publisher $publisher, DynamicRecord $record, PublishSkips $skips): ?string
    {
        if ($publisher->state($record) !== PublishState::Draft) {
            return 'already published';
        }

        return $skips->reason($record);
    }
}
