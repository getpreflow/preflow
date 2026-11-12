<?php

declare(strict_types=1);

namespace Preflow\Rbac\Console;

use Preflow\Core\Application;
use Preflow\Data\DataManager;
use Preflow\DevTools\Command\CommandInterface;
use Preflow\Rbac\RoleStore;
use Preflow\Rbac\Staff\StaffType;

/**
 * rbac:install: creates the rbac_role table when it is missing (and the staff table, where rbac
 * defines the staff type: StaffType) and seeds the roles (the staff
 * role "admin" with everything when there is no staff role, then config/roles.php), as Folio's
 * rbac extension does on folio:extensions:apply. Safe to repeat: a role an editor changed stays.
 * From then on staff may do what their roles grant (before, staff may do everything).
 * The package lists it in composer.json (extra.preflow.commands).
 */
final class InstallCommand implements CommandInterface
{
    use RbacCommand;

    public function getName(): string { return 'rbac:install'; }
    public function getDescription(): string { return 'Create the roles table and seed the roles (admin, config/roles.php); safe to repeat'; }

    public function execute(array $args): int
    {
        $c = $this->app()->container();
        $store = $c->get(RoleStore::class);
        $was = $store->installed();
        $store->install();
        if ($c->bound(StaffType::class)) {
            $c->get(StaffType::class)->install($c->get(DataManager::class));
        }
        $store->seed(RoleStore::config($c->get(Application::class)->basePath('config/roles.php')));
        echo $was ? "rbac was installed already; roles seeded.\n" : "rbac installed.\n";
        foreach (RoleStore::POPULATIONS as $population) {
            $names = array_keys($store->records($population));
            if ($names !== []) {
                echo "{$population}: " . implode(', ', $names) . "\n";
            }
        }

        return 0;
    }
}
