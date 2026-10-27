<?php

declare(strict_types=1);

namespace Preflow\Folio\Members;

use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Data\DataManager;
use Preflow\Data\Locales;
use Preflow\Folio\Extension\Attributes\DeclaresPermission;
use Preflow\Folio\Extension\Attributes\FolioExtension;
use Preflow\Members\MembersConfig;
use Preflow\Members\MemberType;
use Preflow\Rbac\RoleStore;

/**
 * The members admin (preflow/members' accounts in Folio), Folio's built-in extension "members",
 * there when preflow/members is installed: the member type (the members core's, MemberType, with
 * what models/member.json adds for the admin: the list, the menu section, roles as rbac roles;
 * its admin labels in the members package's lang/{locale}/members-admin.php), its roles (rbac,
 * which the admin needs: FolioServiceProvider checks it at boot), the owner's Decision panel (MemberAdmin) and the
 * settings under Settings → Members settings (their labels, the permissions' and the seeded
 * roles' are members-admin keys too), which FolioMembersSettings gives the members core.
 */
#[FolioExtension(name: 'members', label: 'Members', icon: 'ui-users', version: '1.2.0', translations: 'members-admin')]
#[DeclaresPermission('members.access', 'Use the members area')] // in the admin: members-admin.permissions.access
#[DeclaresPermission('members.decide', 'Accept or decline members')]
final class Extension extends \Preflow\Folio\Extension\Extension
{
    /** @return array<string, array<string, mixed>> */
    public function settings(): array
    {
        return [
            'registration_open' => ['type' => 'boolean', 'label' => 'Registration open', 'default' => true],
            'notice_email' => ['type' => 'string', 'label' => 'Notify about waiting members', 'help' => 'Address that gets a mail when someone waits for approval'],
            // No default here: left empty, the panel takes lang/{locale}/members.php mail.accepted.body /
            // mail.declined.body, so a site words them in its own lang files (see MemberAdmin).
            'accept_message' => ['type' => 'text', 'label' => 'Accept message', 'translatable' => true, 'help' => 'Pre-filled in the Decision panel; empty means the standard text shown in the field. {name} and {login_url} are filled in.'],
            'decline_message' => ['type' => 'text', 'label' => 'Decline message', 'translatable' => true, 'help' => 'Pre-filled in the Decision panel; empty means the standard text shown in the field. {name} is filled in.'],
        ];
    }

    /**
     * The members core's member type with the admin's additions (models/member.json): its keys
     * added, its fields' settings over the core's (roles: rbac_roles), the core's field order.
     */
    public function model(array $schema): array
    {
        if (($schema['key'] ?? null) !== MembersConfig::TYPE) {
            return $schema;
        }
        $core = MemberType::schema();
        $fields = $core['fields'];
        foreach ((array) ($schema['fields'] ?? []) as $name => $field) {
            $fields[$name] = array_replace((array) ($fields[$name] ?? []), (array) $field);
        }

        unset($core['fields'], $schema['fields']);

        return array_replace($core, $schema) + ['fields' => $fields];
    }

    /** As the core prepares it: storage "members" when the site has it, else "default"; the site's locales as language options. */
    public function prepareModel(array $schema, Container $c): array
    {
        if (($schema['key'] ?? null) !== MembersConfig::TYPE) {
            return $schema;
        }
        // The DataManager is bound only with config/data.php (Container::has() is true for any class).
        $drivers = is_file($c->get(Application::class)->basePath('config/data.php')) ? $c->get(DataManager::class)->driverNames() : [];

        return MemberType::prepare($schema, $drivers, $c->get(Locales::class)->available);
    }

    /**
     * The member roles, created when missing; an existing role (edited in Folio, or made by
     * an earlier version) is never changed, so applying a new version is harmless.
     */
    public function install(Container $c): void
    {
        $roles = $c->get(RoleStore::class);
        $roles->ensure('members', 'pending', 'Waiting for approval', []);
        $roles->ensure('members', 'member', 'Member', [MembersConfig::PERMISSION]);
        $roles->ensure('members', 'rejected', 'Rejected', []);
    }
}
