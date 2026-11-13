<?php

declare(strict_types=1);

namespace Preflow\Folio\Console;

use Preflow\Core\Application;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\DevTools\Command\CommandInterface;
use Preflow\Folio\Content\FrontendRoute;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Publishing\Console\PublishCommand as Publish;

/**
 * @deprecated since 0.71, use "publish" (preflow/publishing).
 * folio:publish still works for one release: it prints a note and runs publish, which also skips
 * the records that were not public before publishing was turned on (the legacy frontend
 * "published" field, when the type still has it).
 */
final class PublishCommand implements CommandInterface
{
    use BootsApplication;

    public function getName(): string { return 'folio:publish'; }
    public function getDescription(): string { return 'Deprecated: use publish'; }

    public function execute(array $args): int
    {
        echo "Note: \"folio:publish\" is deprecated; use \"publish\".\n";

        return (new Publish(fn (): Application => $this->app(), self::legacyGate(...), 'folio:publish'))->execute($args);
    }

    /**
     * The legacy "published" field and value, when the type still has that field: a record without
     * that value was not public before.
     *
     * @return (\Closure(DynamicRecord): ?string)|null
     */
    private static function legacyGate(Application $app, string $type): ?\Closure
    {
        $c = $app->container();
        $frontend = $c->get(TypeCatalog::class)->get($type)?->frontend;
        if ($frontend === null) {
            return null;
        }
        $route = FrontendRoute::fromConfig($type, $frontend);
        $fields = $c->get(DataManager::class)->getTypeRegistry()?->get($type)->fields ?? [];
        if (!isset($fields[$route->publishedField])) {
            return null;
        }
        [$field, $value] = [$route->publishedField, $route->publishedValue];

        return static fn (DynamicRecord $record): ?string => $record->get($field) !== $value
            ? "{$field} is not \"" . (is_scalar($value) ? (string) $value : '') . '"'
            : null;
    }
}
