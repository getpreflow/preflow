<?php

declare(strict_types=1);

namespace Preflow\Folio\Console;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\DevTools\Command\CommandInterface;
use Preflow\Folio\Content\FrontendRoute;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Publishing\Publisher;
use Preflow\Folio\Publishing\PublishState;
use Preflow\Folio\Publishing\Scheduler;
use Preflow\Validation\ValidationException;

/**
 * Publish the drafts of a type, e.g. after turning "publishing" on for a type
 * with content. By default only records that were public before (the legacy
 * frontend.published field, when the type has it) and have never been
 * published; --all publishes every working copy, unfinished edits included.
 */
final class PublishCommand implements CommandInterface
{
    use BootsApplication;

    public function getName(): string { return 'folio:publish'; }
    public function getDescription(): string { return 'Publish drafts of a Folio type ([--id=<id>] [--all])'; }

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
            echo "Usage: preflow folio:publish <type> [--id=<id>] [--all] — \"{$type}\" is not a publishing type.\n";
            return 1;
        }

        $dm = $c->get(DataManager::class);
        $records = $id !== null
            ? array_filter([$dm->findType($type, $id)])
            : $dm->queryType($type)->get()->items();
        $legacy = $this->legacyGate($c->get(TypeCatalog::class), $dm, $type);

        $failed = false;
        foreach ($records as $record) {
            $key = $type . '/' . $record->getId();
            $skip = $all ? null : $this->skipReason($publisher, $record, $legacy);
            if ($skip !== null) {
                echo "Skipped {$key} ({$skip}; use --all to publish anyway)\n";
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

    /** @param array{0: string, 1: mixed}|null $legacy */
    private function skipReason(Publisher $publisher, DynamicRecord $record, ?array $legacy): ?string
    {
        if ($publisher->state($record) !== PublishState::Draft) {
            return 'already published';
        }
        if ($legacy !== null && $record->get($legacy[0]) !== $legacy[1]) {
            return "{$legacy[0]} is not \"" . (is_scalar($legacy[1]) ? (string) $legacy[1] : '') . '"';
        }

        return null;
    }

    /**
     * The legacy "published" field and value, when the type still has that field.
     *
     * @return array{0: string, 1: mixed}|null
     */
    private function legacyGate(TypeCatalog $catalog, DataManager $dm, string $type): ?array
    {
        $frontend = $catalog->get($type)?->frontend;
        if ($frontend === null) {
            return null;
        }
        $route = FrontendRoute::fromConfig($type, $frontend);
        $fields = $dm->getTypeRegistry()?->get($type)->fields ?? [];

        return isset($fields[$route->publishedField]) ? [$route->publishedField, $route->publishedValue] : null;
    }
}
