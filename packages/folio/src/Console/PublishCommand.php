<?php

declare(strict_types=1);

namespace Preflow\Folio\Console;

use Preflow\Data\DataManager;
use Preflow\DevTools\Command\CommandInterface;
use Preflow\Folio\Publishing\Publisher;
use Preflow\Folio\Publishing\Scheduler;
use Preflow\Validation\ValidationException;

/** Publish every working copy of a type (e.g. after turning "publishing" on), or one. */
final class PublishCommand implements CommandInterface
{
    use BootsApplication;

    public function getName(): string { return 'folio:publish'; }
    public function getDescription(): string { return 'Publish all records of a Folio type (or --id=<id>)'; }

    public function execute(array $args): int
    {
        $type = '';
        $id = null;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--id=')) {
                $id = substr($arg, 5);
            } elseif (!str_starts_with($arg, '--')) {
                $type = $arg;
            }
        }

        $c = $this->app()->container();
        $publisher = $c->get(Publisher::class);
        if ($type === '' || !$publisher->enabled($type)) {
            echo "Usage: preflow folio:publish <type> [--id=<id>] — \"{$type}\" is not a publishing type.\n";
            return 1;
        }

        $dm = $c->get(DataManager::class);
        $records = $id !== null
            ? array_filter([$dm->findType($type, $id)])
            : $dm->queryType($type)->get()->items();

        $failed = false;
        foreach ($records as $record) {
            $key = $type . '/' . $record->getId();
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
}
