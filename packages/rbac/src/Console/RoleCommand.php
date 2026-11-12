<?php

declare(strict_types=1);

namespace Preflow\Rbac\Console;

use Preflow\Auth\Access\PermissionRegistry;
use Preflow\DevTools\Command\CommandInterface;
use Preflow\Rbac\DeclaredPermissions;
use Preflow\Rbac\RoleList;
use Preflow\Rbac\RoleStore;

/**
 * rbac:role <name> [--population=staff] [--label=<label>] [--grant=a,b] [--revoke=a,b] [--allow-undeclared]:
 * creates the role or changes it. A granted name must be "*", a declared permission (auth's
 * PermissionRegistry), a declared pattern or a pattern that covers a declared permission
 * (DeclaredPermissions); any other is refused by name and nothing changes, unless
 * --allow-undeclared grants it (as the seed from config/roles.php does), naming each. A new role without --label gets one from its
 * name ("content_lead" → "Content lead"). "*" is not revoked from the only staff role granting
 * it (as rbac:role:delete keeps that role). The package lists it in composer.json (extra.preflow.commands).
 */
final class RoleCommand implements CommandInterface
{
    use RbacCommand;

    /** As the role type validates a name. */
    private const string NAME = '/^[a-z][a-z0-9_-]*$/';

    private const string USAGE = "Usage: rbac:role <name> [--population=staff|members] [--label=<label>] [--grant=a,b] [--revoke=a,b] [--allow-undeclared]\n";

    public function getName(): string { return 'rbac:role'; }
    public function getDescription(): string { return 'Create or change a role: rbac:role <name> [--population=staff] [--label=…] [--grant=a,b] [--revoke=a,b] [--allow-undeclared]'; }

    public function execute(array $args): int
    {
        $arguments = self::arguments($args);
        if (count($arguments) !== 1) {
            echo self::USAGE;

            return 1;
        }
        $name = $arguments[0];
        if (preg_match(self::NAME, $name) !== 1) {
            echo "A role name is lower case: a letter, then letters, digits, \"_\" or \"-\".\n";

            return 1;
        }
        $population = self::population($args);
        if ($population === null) {
            return 1;
        }
        $label = self::option($args, 'label');
        if ($label !== null && trim($label) === '') {
            echo "The label is empty.\n";

            return 1;
        }
        $grant = RoleList::normalize((string) self::option($args, 'grant', ''));
        $revoke = RoleList::normalize((string) self::option($args, 'revoke', ''));

        $c = $this->app()->container();
        $store = $c->get(RoleStore::class);
        if (!$store->installed()) {
            echo "rbac is not installed here yet; run php preflow rbac:install first.\n";

            return 1;
        }
        $undeclared = DeclaredPermissions::undeclared($c->get(PermissionRegistry::class), $grant);
        $allowUndeclared = in_array('--allow-undeclared', $args, true);
        if ($undeclared !== [] && !$allowUndeclared) {
            echo 'Not a declared permission: ' . implode(', ', $undeclared) . "; nothing changed. To grant them anyway, add --allow-undeclared.\n";

            return 1;
        }

        if ($population === 'staff' && in_array('*', $revoke, true) && self::onlyEverything($store->rolesFor('staff'), $name)) {
            echo self::onlyEverythingMessage($name, 'Nothing changed.');

            return 1;
        }

        if ($undeclared !== []) {
            echo 'Not a declared permission: ' . implode(', ', $undeclared) . "; granted anyway.\n";
        }
        $created = $store->put($population, $name, $label === null ? null : trim($label), $grant, $revoke);
        $role = $store->records($population)[$name];
        $patterns = RoleList::normalize($role->get('permissions'));
        echo ($created ? 'Created' : 'Changed') . " {$population} role \"{$name}\" (" . (string) $role->get('label') . '): '
            . ($patterns === [] ? 'grants nothing' : implode(', ', $patterns)) . "\n";

        return 0;
    }
}
