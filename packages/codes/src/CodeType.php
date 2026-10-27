<?php

declare(strict_types=1);

namespace Preflow\Codes;

use Preflow\Core\Container\Container;
use Preflow\Data\DataManager;
use Preflow\Data\Migration\MigrationRegistry;
use Preflow\Data\Schema\RecordTables;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Data\TypeRegistry;

/**
 * The code type ("code"), the core's own: models/code.json, on the storage alias "codes"
 * when the site has it (else "default"). The core defines it with the data layer unless
 * something did already: Folio's codes extension (its model file adds what its admin needs)
 * or a site model file code.json.
 */
final class CodeType
{
    public const string FILE = __DIR__ . '/../models/code.json';

    /** The schema as the package ships it (a model file's shape). @return array<string, mixed> */
    public static function schema(): array
    {
        $schema = json_decode((string) file_get_contents(self::FILE), true, 512, JSON_THROW_ON_ERROR);

        return is_array($schema) ? $schema : throw new \LogicException(self::FILE . ' is no model schema.');
    }

    /**
     * $schema on the site: storage "codes" when $drivers has it, else "default".
     *
     * @param array<string, mixed> $schema
     * @param list<string> $drivers the DataManager's storage names
     * @return array<string, mixed>
     */
    public static function prepare(array $schema, array $drivers): array
    {
        $schema['storage'] = in_array(Codes::STORAGE, $drivers, true) ? Codes::STORAGE : 'default';

        return $schema;
    }

    /**
     * Defines the code type with the data layer when nothing has (and the site has a data
     * config; without a models directory it gives the data layer a registry), and has
     * `preflow migrate` create or complete its table.
     */
    public static function define(Container $c): void
    {
        // The DataManager is bound only with config/data.php (Container::has() is true for any class).
        if (!$c->bound(DataManager::class)) {
            return;
        }
        $dm = $c->get(DataManager::class);
        $registry = $dm->getTypeRegistry() ?? $dm->ensureTypeRegistry(
            $c->bound(TypeRegistry::class) ? $c->get(TypeRegistry::class) : new TypeRegistry(self::modelsPath($c->get(\Preflow\Core\Application::class)), $c->get(\Preflow\Data\Locales::class)),
        );
        if (!$c->bound(TypeRegistry::class)) {
            $c->instance(TypeRegistry::class, $registry);
        }
        if ($registry->has('code')) {
            return;
        }
        $definition = $registry->define(self::prepare(self::schema(), $dm->driverNames()));
        $tables = static fn (): array => [RecordTables::entry($definition)];
        if ($c->bound(RecordTables::class)) {
            $c->get(RecordTables::class)->add('codes', $tables);
        }
        if ($c->bound(MigrationRegistry::class)) {
            $c->get(MigrationRegistry::class)->afterMigrate(
                'codes.table',
                static fn (): array => (new RecordTableSync($c->get(DataManager::class)))->syncAll($tables()),
            );
        }
    }

    /** config/data.php models_path, as the data layer reads it (config/models without one). */
    private static function modelsPath(\Preflow\Core\Application $app): string
    {
        $file = $app->basePath('config/data.php');
        $data = is_file($file) ? require $file : [];

        return is_array($data) && is_string($data['models_path'] ?? null) ? $data['models_path'] : $app->basePath('config/models');
    }
}
