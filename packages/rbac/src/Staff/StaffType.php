<?php

declare(strict_types=1);

namespace Preflow\Rbac\Staff;

use Preflow\Auth\Record\RecordUserProvider;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Data\DataManager;
use Preflow\Data\Migration\MigrationRegistry;
use Preflow\Data\Schema\RecordTables;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Data\TypeDefinition;
use Preflow\Rbac\RoleStore;
use Preflow\Rbac\StaffManagers;

/**
 * The staff type ("staff"), rbac's own: models/staff.json, on the storage alias "staff" when the
 * site has it, else "default". rbac defines it with the data layer when a site signs staff in with
 * it (config/auth.php has a RecordUserProvider of type "staff") and nothing defined it already
 * (Folio's rbac admin, whose model adds what its admin needs, when its part "model:staff" is on).
 * Its table is made by `php preflow rbac:install`, and kept up to date by `preflow migrate` once
 * rbac is installed. A container instance when rbac defined it.
 */
final readonly class StaffType
{
    public const string FILE = __DIR__ . '/../../models/staff.json';

    public function __construct(public TypeDefinition $definition) {}

    /** Creates or completes the staff table (SQL storage; JSON storage makes it on the first write). */
    public function install(DataManager $dm): void
    {
        (new RecordTableSync($dm))->sync($this->definition);
    }

    /** The schema as the package ships it (a model file's shape). @return array<string, mixed> */
    public static function schema(): array
    {
        $schema = json_decode((string) file_get_contents(self::FILE), true, 512, JSON_THROW_ON_ERROR);

        return is_array($schema) ? $schema : throw new \LogicException(self::FILE . ' is no model schema.');
    }

    /** Defines the type when the site's auth config uses it and nothing has (after RoleType::define(), which gives the data layer a registry). */
    public static function define(Container $c): void
    {
        if (!$c->bound(DataManager::class)) {
            return;
        }
        $dm = $c->get(DataManager::class);
        $registry = $dm->getTypeRegistry();
        if ($registry === null || $registry->has(StaffManagers::TYPE) || !self::signsIn($c->get(Application::class))) {
            return;
        }
        $schema = self::schema();
        $schema['storage'] = in_array(StaffManagers::TYPE, $dm->driverNames(), true) ? StaffManagers::TYPE : 'default';
        $type = new self($registry->define($schema));
        $c->instance(self::class, $type);
        $definition = $type->definition;
        if ($c->bound(RecordTables::class)) {
            $c->get(RecordTables::class)->add('rbac.staff', static fn (): array => [RecordTables::entry($definition)]);
        }
        if ($c->bound(MigrationRegistry::class)) {
            $c->get(MigrationRegistry::class)->afterMigrate('rbac.staff', static fn (): array => $c->get(RoleStore::class)->installed()
                ? (new RecordTableSync($c->get(DataManager::class)))->sync($definition)
                : []);
        }
    }

    /** Whether config/auth.php has a record provider of the staff type. */
    private static function signsIn(Application $app): bool
    {
        $auth = $app->configFile('auth');
        foreach (is_array($auth) && is_array($auth['providers'] ?? null) ? $auth['providers'] : [] as $provider) {
            if (is_array($provider) && ($provider['class'] ?? null) === RecordUserProvider::class && ($provider['type'] ?? null) === StaffManagers::TYPE) {
                return true;
            }
        }

        return false;
    }
}
