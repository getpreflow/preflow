<?php

declare(strict_types=1);

namespace Preflow\Rbac\Console;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\DevTools\Command\CommandInterface;
use Preflow\Rbac\ConfigUsers;
use Preflow\Rbac\RoleList;
use Preflow\Rbac\RoleStore;
use Preflow\Rbac\Staff\StaffRefused;
use Preflow\Rbac\StaffManagers;

/**
 * staff:import-config: moves the config users of the staff guard (StaffAccounts::guard():
 * config/rbac.php 'guard', else the admin's staff guard, else the default guard; directly or
 * inside a chain) into staff records: one per e-mail that has no record yet, with the password
 * hash as is (so the old password keeps working, now decided by the record), roles mapped by name
 * to the staff roles that exist (an unknown name is skipped with a warning; the rules would
 * refuse it), active from epoch "0". Safe to repeat: an e-mail with a record is skipped. Prints
 * one line per account, never a hash. Until 0.70 also as folio:staff:import-config
 * (ImportConfigStaffAliasCommand). The package lists it in composer.json (extra.preflow.commands).
 */
final class ImportConfigStaffCommand implements CommandInterface
{
    use StaffCommand;

    public function getName(): string { return 'staff:import-config'; }
    public function getDescription(): string { return 'Create staff accounts from the config users of the staff guard (repeatable)'; }

    public function execute(array $args): int
    {
        $c = $this->app()->container();
        $accounts = self::accounts($c);
        if ($accounts === null) {
            return 1;
        }

        $provider = $accounts->users();
        $users = $provider === null ? [] : ConfigUsers::of($provider);
        if ($users === []) {
            echo "staff:import-config — the staff guard \"{$accounts->guard()}\" has no config users to import.\n";

            return 0;
        }

        $dm = $c->get(DataManager::class);
        $roles = (new RoleStore($dm))->rolesFor('staff');
        $type = $dm->getTypeRegistry()->get(StaffManagers::TYPE);
        $created = $skipped = $failed = 0;
        foreach ($users as $user) {
            $email = mb_strtolower(trim((string) ($user['email'] ?? '')));
            if ($email === '') {
                continue;
            }
            if ($accounts->find($email) !== null) {
                echo "skipped {$email} (already a staff account)\n";
                $skipped++;
                continue;
            }

            $names = [];
            foreach (RoleList::normalize($user['roles'] ?? []) as $name) {
                if (isset($roles[$name])) {
                    $names[] = $name;
                } else {
                    echo "warning: {$email}: unknown role \"{$name}\" skipped (no staff role of that name)\n";
                }
            }

            $record = DynamicRecord::fromArray($type, [
                $type->idField => bin2hex(random_bytes(16)),
                'email' => $email,
                'name' => $email,
                'password_hash' => (string) ($user['password_hash'] ?? ''),
                'roles' => $names,
                'active' => true,
                'auth_epoch' => '0',
                'created_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ]);
            try {
                $accounts->checkSave($record);
            } catch (StaffRefused $e) {
                echo "failed {$email}: {$e->getMessage()}\n";
                $failed++;
                continue;
            }
            $dm->saveType($record, false);
            echo "created {$email}" . ($names === [] ? '' : ' (' . implode(', ', $names) . ')') . "\n";
            $created++;
        }

        echo "staff:import-config — created {$created}, skipped {$skipped}" . ($failed > 0 ? ", failed {$failed}" : '') . "\n";

        return $failed > 0 ? 1 : 0;
    }
}
