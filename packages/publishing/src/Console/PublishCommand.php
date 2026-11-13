<?php

declare(strict_types=1);

namespace Preflow\Publishing\Console;

use Preflow\Core\Application;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\DevTools\Command\CommandInterface;
use Preflow\Publishing\Publisher;
use Preflow\Publishing\PublishState;
use Preflow\Publishing\Scheduler;
use Preflow\Validation\ValidationException;

/**
 * publish <type> publishes the drafts of a type, e.g. after turning "publishing" on for a type
 * with content. By default only records that have never been published; --all publishes every
 * working copy, unfinished edits included; --id=<id> only that record. The package lists it in
 * composer.json (extra.preflow.commands).
 */
final class PublishCommand implements CommandInterface
{
    /**
     * @param (\Closure(): Application)|null $appFactory returns a booted application
     * @param (\Closure(Application, string): (\Closure(DynamicRecord): ?string))|null $skips
     *        a further reason to skip a never-published record of the type without --all (null: publish it)
     * @param string $name the name the command is called by
     */
    public function __construct(
        private readonly ?\Closure $appFactory = null,
        private readonly ?\Closure $skips = null,
        private readonly string $name = 'publish',
    ) {}

    public function getName(): string { return $this->name; }
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

        $app = $this->app();
        $c = $app->container();
        $publisher = $c->get(Publisher::class);
        if ($type === '' || !$publisher->enabled($type)) {
            echo "Usage: preflow {$this->name} <type> [--id=<id>] [--all] — \"{$type}\" is not a publishing type.\n";
            return 1;
        }

        $dm = $c->get(DataManager::class);
        $records = $id !== null
            ? array_filter([$dm->findType($type, $id)])
            : $dm->queryType($type)->get()->items();
        $skip = $this->skips !== null ? ($this->skips)($app, $type) : null;

        $failed = false;
        foreach ($records as $record) {
            $key = $type . '/' . $record->getId();
            $reason = $all ? null : $this->skipReason($publisher, $record, $skip);
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

    /** @param (\Closure(DynamicRecord): ?string)|null $skip */
    private function skipReason(Publisher $publisher, DynamicRecord $record, ?\Closure $skip): ?string
    {
        if ($publisher->state($record) !== PublishState::Draft) {
            return 'already published';
        }

        return $skip !== null ? $skip($record) : null;
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
