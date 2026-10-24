<?php

declare(strict_types=1);

namespace Preflow\Rbac\Console;

use Preflow\Auth\ChainUserProvider;
use Preflow\Auth\ConfigUserProvider;
use Preflow\Auth\LazyUserProvider;
use Preflow\Auth\UserProviderInterface;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\DevTools\Command\CommandInterface;
use Preflow\Folio\Console\BootsApplication;
use Preflow\Rbac\RoleList;
use Preflow\Rbac\RoleStore;
use Preflow\Rbac\StaffManagers;
use Preflow\Rbac\Types\StaffRules;
use Preflow\Validation\ValidationException;

/**
 * Moves the config users of the default guard (directly or inside a chain) into staff records:
 * one per e-mail that has no record yet, with the password hash as is (so the old password keeps
 * working, now decided by the record), roles mapped by name to the staff roles that exist (an
 * unknown name is skipped with a warning — StaffRules would refuse it), active from epoch "0".
 * Safe to repeat: an e-mail with a record is skipped. Prints one line per account, never a hash.
 * The package lists it in composer.json (extra.preflow.commands).
 */
final class ImportConfigStaffCommand implements CommandInterface
{
    use BootsApplication;

    public function getName(): string { return 'folio:staff:import-config'; }
    public function getDescription(): string { return 'Create staff accounts from the config users of the default guard (repeatable)'; }

    public function execute(array $args): int
    {
        $c = $this->app()->container();
        $dm = $c->get(DataManager::class);
        if (!(new StaffManagers($dm))->enabled()) {
            echo "folio:staff:import-config — the staff type is not enabled. List \"model:staff\" under rbac's \"enabled_parts\" in config/folio/extensions.json (then run folio:extensions:apply).\n";

            return 1;
        }

        $users = [];
        try {
            self::collect($c->get(UserProviderInterface::class), $users);
        } catch (\Throwable) { // no config/auth.php: nothing bound
        }
        if ($users === []) {
            echo "folio:staff:import-config — the default guard has no config users to import.\n";

            return 0;
        }

        $roles = (new RoleStore($dm))->rolesFor('staff');
        $rules = $c->get(StaffRules::class);
        $type = $dm->getTypeRegistry()->get(StaffManagers::TYPE);
        $created = $skipped = $failed = 0;
        foreach ($users as $user) {
            $email = mb_strtolower(trim((string) ($user['email'] ?? '')));
            if ($email === '') {
                continue;
            }
            if ($dm->queryType(StaffManagers::TYPE)->where('email', $email)->first() !== null) {
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
                $rules->beforeSave($record);
            } catch (ValidationException $e) {
                echo "failed {$email}: " . implode(' ', array_merge(...array_values($e->errors()))) . "\n";
                $failed++;
                continue;
            }
            $dm->saveType($record, false);
            echo "created {$email}" . ($names === [] ? '' : ' (' . implode(', ', $names) . ')') . "\n";
            $created++;
        }

        echo "folio:staff:import-config — created {$created}, skipped {$skipped}" . ($failed > 0 ? ", failed {$failed}" : '') . "\n";

        return $failed > 0 ? 1 : 0;
    }

    /**
     * The entries of every ConfigUserProvider in the provider (a chain's members, built lazy ones).
     *
     * @param list<array<string, mixed>> $found
     */
    private static function collect(?UserProviderInterface $provider, array &$found): void
    {
        if ($provider instanceof LazyUserProvider) {
            $provider = $provider->inner();
        }
        if ($provider instanceof ConfigUserProvider) {
            array_push($found, ...$provider->entries());
        } elseif ($provider instanceof ChainUserProvider) {
            foreach ($provider->providers() as $member) {
                self::collect($member, $found);
            }
        }
    }
}
