<?php

declare(strict_types=1);

namespace Preflow\Members;

use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Data\DataManager;
use Preflow\Data\Locales;
use Preflow\Folio\Extension\Attributes\DeclaresPermission;
use Preflow\Folio\Extension\Attributes\FolioExtension;
use Preflow\Rbac\RoleStore;

/**
 * Member accounts in Folio: the member type (models/member.json; its admin labels in
 * lang/{locale}/members-admin.php), its roles, the owner's
 * Decision panel (Types/MemberAdmin) and the settings under Settings → Members settings (their
 * labels, the permissions' and the seeded roles' are members-admin keys too).
 * MembersServiceProvider binds the services.
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
     * The member type on the storage alias "members" when the site has it, else on "default";
     * the language options are the site's locales.
     */
    public function prepareModel(array $schema, Container $c): array
    {
        if (($schema['key'] ?? null) !== MembersConfig::TYPE) {
            return $schema;
        }
        // The DataManager is bound only with config/data.php (Container::has() is true for any class).
        $drivers = is_file($c->get(Application::class)->basePath('config/data.php')) ? $c->get(DataManager::class)->driverNames() : [];
        $schema['storage'] = in_array(MembersConfig::STORAGE, $drivers, true) ? MembersConfig::STORAGE : 'default';
        $schema['fields']['language']['options'] = Languages::options($c->get(Locales::class)->available);

        return $schema;
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
