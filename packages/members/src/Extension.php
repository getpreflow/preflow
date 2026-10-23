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
 * Member accounts in Folio: the member type (models/member.json), its roles, the owner's
 * Decision panel (Types/MemberAdmin) and the settings under Settings → Members settings.
 * MembersServiceProvider binds the services.
 */
#[FolioExtension(name: 'members', label: 'Members', icon: 'ui-users', version: '1.2.0')]
#[DeclaresPermission('members.access', 'Use the members area')]
#[DeclaresPermission('members.decide', 'Accept or decline members')]
final class Extension extends \Preflow\Folio\Extension\Extension
{
    /** @return array<string, array<string, mixed>> */
    public function settings(): array
    {
        return [
            'registration_open' => ['type' => 'boolean', 'label' => 'Registration open', 'default' => true],
            'notice_email' => ['type' => 'string', 'label' => 'Notify about waiting members', 'help' => 'Address that gets a mail when someone waits for approval'],
            'accept_message' => ['type' => 'text', 'label' => 'Accept message', 'translatable' => true, 'help' => 'Pre-filled in the Decision panel. {name} and {login_url} are filled in.', 'default' => [
                'en' => "Hi {name},\n\nyour account is ready. Sign in here: {login_url}\n\nBest regards",
                'de' => "Hallo {name},\n\ndein Konto ist bereit. Hier meldest du dich an: {login_url}\n\nFreundliche Grüsse",
                'fr' => "Bonjour {name},\n\nvotre compte est prêt. Connectez-vous ici : {login_url}\n\nMeilleures salutations",
            ]],
            'decline_message' => ['type' => 'text', 'label' => 'Decline message', 'translatable' => true, 'help' => 'Pre-filled in the Decision panel. {name} is filled in.', 'default' => [
                'en' => "Hi {name},\n\nthank you for your interest. Unfortunately we can't give you an account at this time.\n\nBest regards",
                'de' => "Hallo {name},\n\nvielen Dank für dein Interesse. Leider können wir dir im Moment kein Konto geben.\n\nFreundliche Grüsse",
                'fr' => "Bonjour {name},\n\nmerci de votre intérêt. Malheureusement, nous ne pouvons pas vous ouvrir de compte pour le moment.\n\nMeilleures salutations",
            ]],
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
