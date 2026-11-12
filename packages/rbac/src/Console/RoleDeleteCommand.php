<?php

declare(strict_types=1);

namespace Preflow\Rbac\Console;

use Preflow\Data\DataManager;
use Preflow\DevTools\Command\CommandInterface;
use Preflow\Rbac\RoleList;
use Preflow\Rbac\RoleStore;
use Preflow\Rbac\StaffManagers;

/**
 * rbac:role:delete <name> [--population=staff]: deletes the role. A staff role is kept while a
 * staff account holds it, and while it is the only staff role granting "*" (so the
 * administrator role cannot go). The package lists it in composer.json (extra.preflow.commands).
 */
final class RoleDeleteCommand implements CommandInterface
{
    use RbacCommand;

    public function getName(): string { return 'rbac:role:delete'; }
    public function getDescription(): string { return 'Delete a role: rbac:role:delete <name> [--population=staff]'; }

    public function execute(array $args): int
    {
        $arguments = self::arguments($args);
        if (count($arguments) !== 1) {
            echo "Usage: rbac:role:delete <name> [--population=staff|members]\n";

            return 1;
        }
        $name = $arguments[0];
        $population = self::population($args);
        if ($population === null) {
            return 1;
        }
        $c = $this->app()->container();
        $store = $c->get(RoleStore::class);
        if (!$store->installed()) {
            echo "rbac is not installed here yet; run php preflow rbac:install first.\n";

            return 1;
        }
        $roles = $store->rolesFor($population);
        if (!isset($roles[$name])) {
            echo "There is no {$population} role \"{$name}\".\n";

            return 1;
        }
        if ($population === 'staff') {
            $holders = array_filter((new StaffManagers($c->get(DataManager::class)))->accounts(), static fn (array $a): bool => in_array($name, $a['roles'], true));
            if ($holders !== []) {
                echo "The role \"{$name}\" is held by " . count($holders) . ' staff account' . (count($holders) === 1 ? '' : 's') . "; take it from them first. Nothing deleted.\n";

                return 1;
            }
            $everything = array_keys(array_filter($roles, static fn (array $patterns): bool => in_array('*', RoleList::normalize($patterns), true)));
            if ($everything === [$name]) {
                echo "The role \"{$name}\" is the only staff role granting everything (\"*\"); it stays. Nothing deleted.\n";

                return 1;
            }
        }
        $store->delete($population, $name);
        echo "Deleted {$population} role \"{$name}\".\n";

        return 0;
    }
}
