<?php

declare(strict_types=1);

namespace Preflow\Rbac\Console;

use Preflow\DevTools\Command\CommandInterface;
use Preflow\Rbac\RoleList;
use Preflow\Rbac\RoleStore;

/**
 * rbac:roles [--population=staff]: the population's roles, one per line: name, label and the
 * permission patterns they grant. The package lists it in composer.json (extra.preflow.commands).
 */
final class RolesCommand implements CommandInterface
{
    use RbacCommand;

    public function getName(): string { return 'rbac:roles'; }
    public function getDescription(): string { return 'List the roles: rbac:roles [--population=staff|members]'; }

    public function execute(array $args): int
    {
        $population = self::population($args);
        if ($population === null) {
            return 1;
        }
        $store = $this->app()->container()->get(RoleStore::class);
        if (!$store->installed()) {
            echo "rbac is not installed here yet; run php preflow rbac:install.\n";

            return 1;
        }
        $records = $store->records($population);
        if ($records === []) {
            echo "No {$population} roles.\n";

            return 0;
        }
        foreach ($records as $name => $record) {
            $patterns = RoleList::normalize($record->get('permissions'));
            echo "{$name}\t" . (string) $record->get('label') . "\t" . ($patterns === [] ? '(nothing)' : implode(', ', $patterns)) . "\n";
        }

        return 0;
    }
}
