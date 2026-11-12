<?php

declare(strict_types=1);

namespace Preflow\Rbac;

use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Data\DataManager;
use Preflow\Data\Locales;
use Preflow\Data\Migration\MigrationRegistry;
use Preflow\Data\Schema\RecordTables;
use Preflow\Data\TypeRegistry;

/**
 * The role type ("rbac_role"), rbac's own: models/rbac_role.json, on the default storage.
 * rbac defines it with the data layer unless something did already (Folio's rbac admin, whose
 * model adds what its admin needs). Its table is made by `php preflow rbac:install`
 * (RoleStore::install()) or by Folio's table sync when its rbac admin is on, not by rbac's own
 * `preflow migrate` step: the table being there is what tells RbacResolver that rbac is
 * installed. Once it is, `preflow migrate` seeds the roles (RoleStore::seed()) on every deploy.
 */
final class RoleType
{
    public const string KEY = 'rbac_role';

    public const string FILE = __DIR__ . '/../models/rbac_role.json';

    /** The schema as the package ships it (a model file's shape). @return array<string, mixed> */
    public static function schema(): array
    {
        $schema = json_decode((string) file_get_contents(self::FILE), true, 512, JSON_THROW_ON_ERROR);

        return is_array($schema) ? $schema : throw new \LogicException(self::FILE . ' is no model schema.');
    }

    /**
     * Defines the role type with the data layer when nothing has (and the site has a data
     * config; without a models directory it gives the data layer a registry), and lists its
     * table with the data layer's record tables. Either way `preflow migrate` seeds the roles
     * where rbac is installed (step "rbac.seed", after Folio's table sync).
     */
    public static function define(Container $c): void
    {
        // The DataManager is bound only with config/data.php (Container::has() is true for any class).
        if (!$c->bound(DataManager::class)) {
            return;
        }
        $dm = $c->get(DataManager::class);
        $registry = $dm->getTypeRegistry() ?? $dm->ensureTypeRegistry(
            $c->bound(TypeRegistry::class) ? $c->get(TypeRegistry::class) : new TypeRegistry(self::modelsPath($c->get(Application::class)), $c->get(Locales::class)),
        );
        if (!$c->bound(TypeRegistry::class)) {
            $c->instance(TypeRegistry::class, $registry);
        }
        if (!$registry->has(self::KEY)) {
            $definition = $registry->define(self::schema());
            if ($c->bound(RecordTables::class)) {
                $c->get(RecordTables::class)->add('rbac', static fn (): array => [RecordTables::entry($definition)]);
            }
        }
        if ($c->bound(MigrationRegistry::class)) {
            $c->get(MigrationRegistry::class)->afterMigrate('rbac.seed', static fn (): array => self::seed($c));
        }
    }

    /** The migrate step: seeds the roles where rbac is installed. @return list<string> */
    private static function seed(Container $c): array
    {
        $store = $c->get(RoleStore::class);
        if (!$store->installed()) {
            return [];
        }
        $count = static fn (): int => array_sum(array_map(static fn (string $p): int => count($store->rolesFor($p)), RoleStore::POPULATIONS));
        $before = $count();
        $store->seed(RoleStore::config($c->get(Application::class)->basePath('config/roles.php')));
        $added = $count() - $before;

        return $added > 0 ? ["seeded {$added} rbac role" . ($added === 1 ? '' : 's')] : [];
    }

    /** config/data.php models_path, as the data layer reads it (config/models without one). */
    private static function modelsPath(Application $app): string
    {
        $file = $app->basePath('config/data.php');
        $data = is_file($file) ? require $file : [];

        return is_array($data) && is_string($data['models_path'] ?? null) ? $data['models_path'] : $app->basePath('config/models');
    }
}
