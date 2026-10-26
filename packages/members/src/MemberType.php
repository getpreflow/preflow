<?php

declare(strict_types=1);

namespace Preflow\Members;

use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Data\DataManager;
use Preflow\Data\Locales;
use Preflow\Data\Migration\MigrationRegistry;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Data\TypeRegistry;

/**
 * The member type ("member"), the core's own: models/member.json, on the storage alias
 * "members" when the site has it (else "default"), its language options the site's locales.
 * The core defines it with the data layer unless something did already: Folio's members admin
 * (which takes this schema and adds what its admin needs) or a site model file member.json.
 * A site's member.json with "extends": true adds fields to it.
 */
final class MemberType
{
    public const string FILE = __DIR__ . '/../models/member.json';

    /** The schema as the package ships it (a model file's shape). @return array<string, mixed> */
    public static function schema(): array
    {
        $schema = json_decode((string) file_get_contents(self::FILE), true, 512, JSON_THROW_ON_ERROR);

        return is_array($schema) ? $schema : throw new \LogicException(self::FILE . ' is no model schema.');
    }

    /**
     * $schema on the site: storage "members" when $drivers has it, else "default"; the language
     * options are $locales by their own names.
     *
     * @param array<string, mixed> $schema
     * @param list<string> $drivers the DataManager's storage names
     * @param list<string> $locales
     * @return array<string, mixed>
     */
    public static function prepare(array $schema, array $drivers, array $locales): array
    {
        $schema['storage'] = in_array(MembersConfig::STORAGE, $drivers, true) ? MembersConfig::STORAGE : 'default';
        $schema['fields']['language']['options'] = Languages::options($locales);

        return $schema;
    }

    /**
     * Defines the member type with the data layer when nothing has (and the site has a data
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
        // Without a models directory the data layer has no registry: the core brings one for its type.
        $registry = $dm->getTypeRegistry() ?? $dm->ensureTypeRegistry(
            $c->bound(TypeRegistry::class) ? $c->get(TypeRegistry::class) : new TypeRegistry(self::modelsPath($c->get(Application::class)), $c->get(Locales::class)),
        );
        if (!$c->bound(TypeRegistry::class)) {
            $c->instance(TypeRegistry::class, $registry);
        }
        if ($registry->has(MembersConfig::TYPE)) {
            return;
        }
        $schema = self::extend(
            self::prepare(self::schema(), $dm->driverNames(), $c->get(Locales::class)->available),
            self::modelsPath($c->get(Application::class)) . '/' . MembersConfig::TYPE . '.json',
        );
        $definition = $registry->define($schema);
        if ($c->bound(MigrationRegistry::class)) {
            $c->get(MigrationRegistry::class)->afterMigrate(
                'members.table',
                static fn (): array => (new RecordTableSync($c->get(DataManager::class)))->sync($definition),
            );
        }
    }

    /** config/data.php models_path, as the data layer reads it (config/models without one). */
    private static function modelsPath(Application $app): string
    {
        $file = $app->basePath('config/data.php');
        $data = is_file($file) ? require $file : [];

        return is_array($data) && is_string($data['models_path'] ?? null) ? $data['models_path'] : $app->basePath('config/models');
    }

    /**
     * The site's fields from a model file that extends the type ("extends": true): appended,
     * or placed "after" a field it names. A field the type has already is an error.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private static function extend(array $schema, string $file): array
    {
        if (!is_file($file)) {
            return $schema;
        }
        $site = json_decode((string) file_get_contents($file), true);
        if (!is_array($site) || ($site['extends'] ?? false) !== true) {
            return $schema;
        }
        $fields = $schema['fields'];
        foreach (is_array($site['fields'] ?? null) ? $site['fields'] : [] as $name => $field) {
            if (array_key_exists($name, $fields)) {
                throw new \RuntimeException("{$file}: field \"{$name}\" is already a field of the member type.");
            }
            $after = is_array($field) ? ($field['after'] ?? null) : null;
            unset($field['after']);
            $position = is_string($after) ? array_search($after, array_keys($fields), true) : false;
            if ($after !== null && $position === false) {
                throw new \RuntimeException("{$file}: field \"{$name}\" goes \"after\" a field the member type does not have.");
            }
            $fields = $position === false
                ? $fields + [$name => $field]
                : array_slice($fields, 0, $position + 1, true) + [$name => $field] + array_slice($fields, $position + 1, null, true);
        }
        $schema['fields'] = $fields;

        return $schema;
    }
}
