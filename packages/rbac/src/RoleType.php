<?php

declare(strict_types=1);

namespace Preflow\Rbac;

use Preflow\Auth\Access\PermissionRegistry;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Data\DataManager;
use Preflow\Data\Locales;
use Preflow\Data\Migration\MigrationRegistry;
use Preflow\Data\Schema\RecordTables;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Data\TypeRegistry;

/**
 * The role type ("rbac_role"), rbac's own: models/rbac_role.json, on the default storage.
 * rbac defines it with the data layer unless something did already (Folio's rbac admin, whose
 * model adds what its admin needs). Its table is made by `php preflow rbac:install`
 * (RoleStore::install()) or by Folio's table sync when its rbac admin is on. `preflow migrate`
 * never creates it: the table being there is what tells RbacResolver that rbac is installed. Once
 * it is, migrate keeps it current (step "rbac.roles", since 0.84.1: missing columns, the change
 * date and its index, MySQL collations) and seeds the roles (step "rbac.seed", RoleStore::seed()).
 */
final class RoleType
{
    public const string KEY = 'rbac_role';

    public const string FILE = __DIR__ . '/../models/rbac_role.json';

    /**
     * What `preflow migrate` says (once per run) where rbac is not installed yet. Informational:
     * a site that wants staff to may do everything needs no action. @since 0.78
     */
    public const string NOT_INSTALLED = 'rbac is not installed (informational; no action needed if staff may do everything): to use roles run php preflow rbac:install once, or on a Folio site php preflow folio:extensions:apply, to create the roles and staff tables; migrate keeps them current afterwards';

    /** The schema as the package ships it (a model file's shape). @return array<string, mixed> */
    public static function schema(): array
    {
        $schema = json_decode((string) file_get_contents(self::FILE), true, 512, JSON_THROW_ON_ERROR);

        return is_array($schema) ? $schema : throw new \LogicException(self::FILE . ' is no model schema.');
    }

    /**
     * Defines the role type with the data layer when nothing has (and the site has a data
     * config; without a models directory it gives the data layer a registry), and lists its
     * table with the data layer's record tables. Either way `preflow migrate`, where rbac is
     * installed, keeps the role table current (step "rbac.roles", since 0.84.1) and seeds the roles
     * (step "rbac.seed"), both after Folio's table sync.
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
            // The table of an installed rbac is kept current on every deploy (columns, the change date and its
            // index), whoever defined the type: rbac:install made it, no site migration names it, and a
            // site without Folio's rbac admin has no table step of its own. A missing table is not made
            // here (the table being there is what installed means). @since 0.84.1
            $c->get(MigrationRegistry::class)->afterMigrate('rbac.roles', static function () use ($c): array {
                $dm = $c->get(DataManager::class);
                $definition = $dm->getTypeRegistry()?->get(self::KEY);

                return $definition !== null && $c->get(RoleStore::class)->installed() ? (new RecordTableSync($dm))->sync($definition) : [];
            });
            // The hint prints once per process, however often the step runs.
            $told = false;
            $c->get(MigrationRegistry::class)->afterMigrate('rbac.seed', static function () use ($c, &$told): array {
                $lines = self::seed($c);
                if (in_array(self::NOT_INSTALLED, $lines, true)) {
                    if ($told) {
                        return [];
                    }
                    $told = true;
                }

                return $lines;
            }, sqlOnly: false);
        }
    }

    /**
     * The migrate step: seeds the roles where rbac is installed. Where it is not, it says how:
     * migrate does not install rbac itself, since installing switches can() from "staff may do
     * everything" to the stored roles, which a site decides once (`rbac:install`).
     *
     * @return list<string> what it seeded, and each undeclared permission it granted
     */
    private static function seed(Container $c): array
    {
        $store = $c->get(RoleStore::class);
        if (!$store->installed()) {
            return [self::NOT_INSTALLED];
        }
        $count = static fn (): int => array_sum(array_map(static fn (string $p): int => count($store->rolesFor($p)), RoleStore::POPULATIONS));
        $before = $count();
        $report = $store->seed(RoleStore::config($c->get(Application::class)->basePath('config/roles.php')), $c->bound(PermissionRegistry::class) ? $c->get(PermissionRegistry::class) : null);
        $added = $count() - $before;

        return [...($added > 0 ? ["seeded {$added} rbac role" . ($added === 1 ? '' : 's')] : []), ...$report];
    }

    /** config/data.php models_path, as the data layer reads it (config/models without one). */
    private static function modelsPath(Application $app): string
    {
        $file = $app->basePath('config/data.php');
        $data = is_file($file) ? require $file : [];

        return is_array($data) && is_string($data['models_path'] ?? null) ? $data['models_path'] : $app->basePath('config/models');
    }
}
