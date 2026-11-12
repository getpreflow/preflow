<?php

declare(strict_types=1);

namespace Preflow\Folio\Rbac\Types;

use Nyholm\Psr7\Response;
use Preflow\Auth\Access\Access;
use Preflow\Core\Container\Container;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Extension\AfterSave;
use Preflow\Folio\Extension\Attributes\FolioType;
use Preflow\Folio\Extension\BeforeDelete;
use Preflow\Folio\Extension\BeforeSave;
use Preflow\Folio\Extension\FormPanel;
use Preflow\Folio\Extension\RecordAction;
use Preflow\Folio\Extension\Screen\ScreenView;
use Preflow\I18n\Translator;
use Preflow\Rbac\Messages;
use Preflow\Rbac\PermissionCoverage;
use Preflow\Rbac\RoleStore;
use Preflow\Rbac\Staff\StaffAccounts;
use Preflow\Rbac\Staff\StaffRefused;
use Preflow\Rbac\StaffManagers;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Staff accounts in the admin, on rbac's StaffAccounts. The form's save and delete run StaffRules
 * (delegation, lock-out, e-mail). A new account has no password and gets an invitation by mail
 * once it is stored (a mail that can't be queued keeps the account and says so). The Account
 * panel offers, to those with staff.edit who may change the account: Send invitation (while no
 * password is set), Send reset link, Disable / Enable. Each action runs the same rules on the
 * record as it would be saved (StaffAccounts, with the signed-in user as actor), so a crafted
 * POST gets the same refusals as the form (an account above you, the last manager). Disabling
 * (the action, or unticking Active in the form) bumps the epoch (live sessions end), forgets
 * remember-me logins and voids the account's invite and reset links; enabling changes nothing else.
 */
#[FolioType('staff')]
final class StaffAdmin
{
    public const string PERMISSION = 'staff.edit';

    /** Ids of accounts this request creates, invited once stored. @var array<string, true> */
    private array $created = [];

    /** Ids of accounts this request's form save disables, whose devices and links end once stored. @var array<string, true> */
    private array $disabled = [];

    public function __construct(
        private readonly StaffRules $rules,
        private readonly StaffAccounts $accounts,
        private readonly DataManager $dm,
        private readonly PermissionCoverage $coverage,
        private readonly Messages $messages,
        private readonly Translator $translator,
        private readonly Container $container,
    ) {}

    #[BeforeSave]
    public function beforeSave(DynamicRecord $staff): void
    {
        $id = (string) $staff->getId();
        $stored = $id === '' ? null : $this->dm->findType(StaffManagers::TYPE, $id);
        // The rules (StaffAccounts::checkSave()): unticking "Active" disables as the action does, with
        // a new epoch on the record now; its devices and links end once it is saved (afterSave()).
        $this->rules->beforeSave($staff);
        if ($stored !== null && StaffManagers::active($stored->get('active')) && !StaffManagers::active($staff->get('active'))) {
            $this->disabled[$id] = true;
        }
        if ($stored === null) {
            // No password until the invited person sets one: such an account can't sign in.
            if ($staff->get('password_hash') === null) {
                $staff->set('password_hash', '');
            }
            $this->created[$id] = true;
        }
    }

    #[AfterSave]
    public function afterSave(DynamicRecord $staff): void
    {
        $id = (string) $staff->getId();
        if (isset($this->disabled[$id])) {
            unset($this->disabled[$id]);
            $this->accounts->endAccess($staff);
        }
        if (!isset($this->created[$id])) {
            return;
        }
        unset($this->created[$id]);
        // A disabled account's link wouldn't work: it gets its invitation once enabled ("Send invitation").
        if ((string) $staff->get('password_hash') !== '' || !StaffManagers::active($staff->get('active'))) {
            return;
        }
        $session = $this->container->has(SessionInterface::class) ? $this->container->get(SessionInterface::class) : null;
        $error = $this->send('invite', $staff, 'staff.invite_failed');
        if ($session instanceof SessionInterface) {
            $error === null
                ? $session->flash('success', $this->translator->get('rbac-admin.staff.invited', ['email' => (string) $staff->get('email')]))
                : $session->flash('error', $error);
        }
    }

    #[BeforeDelete]
    public function beforeDelete(DynamicRecord $staff): void
    {
        $this->rules->beforeDelete($staff);
    }

    #[FormPanel('rbac-admin.account.title', position: 'side')]
    public function panel(DynamicRecord $staff): ScreenView
    {
        $active = StaffManagers::active($staff->get('active'));
        $invited = (string) $staff->get('password_hash') === '';
        $access = $this->container->has(Access::class) ? $this->container->get(Access::class) : null;
        $mayEdit = $access?->can(self::PERMISSION) ?? false;
        $held = $this->coverage->held();
        $roles = (new RoleStore($this->dm))->rolesFor(StaffManagers::TYPE);
        $above = $held !== null && !PermissionCoverage::coversAll($held, StaffManagers::patterns(StaffManagers::account($staff)['roles'], $roles));

        return new ScreenView('@rbac/account.twig', [
            'active' => $active,
            'invited' => $invited,
            'may_act' => $mayEdit && !$above,
            'above' => $mayEdit && $above,
            'above_message' => $this->messages->get('delegation.account'),
        ]);
    }

    #[RecordAction(name: 'invite', label: 'rbac-admin.actions.invite', permission: self::PERMISSION, toolbar: false)]
    public function invite(DynamicRecord $staff, ServerRequestInterface $request): ResponseInterface
    {
        if (($refused = $this->refused($staff)) !== null) {
            return $this->back($request, $staff, 'error', $refused);
        }
        $error = $this->send('invite', $staff, 'staff.mail_failed');

        return $error !== null
            ? $this->back($request, $staff, 'error', $error)
            : $this->back($request, $staff, 'success', $this->translator->get('rbac-admin.staff.invited', ['email' => (string) $staff->get('email')]));
    }

    #[RecordAction(name: 'reset', label: 'rbac-admin.actions.reset', permission: self::PERMISSION, toolbar: false)]
    public function reset(DynamicRecord $staff, ServerRequestInterface $request): ResponseInterface
    {
        if (($refused = $this->refused($staff)) !== null) {
            return $this->back($request, $staff, 'error', $refused);
        }
        $error = $this->send('reset', $staff, 'staff.mail_failed');

        return $error !== null
            ? $this->back($request, $staff, 'error', $error)
            : $this->back($request, $staff, 'success', $this->translator->get('rbac-admin.staff.reset_sent', ['email' => (string) $staff->get('email')]));
    }

    #[RecordAction(name: 'disable', label: 'rbac-admin.actions.disable', permission: self::PERMISSION, toolbar: false)]
    public function disable(DynamicRecord $staff, ServerRequestInterface $request): ResponseInterface
    {
        $staff = $this->fresh($staff);
        if ($staff === null) {
            return new Response(404, ['Content-Type' => 'text/plain; charset=UTF-8'], 'Not found'); // deleted meanwhile
        }
        try {
            $this->accounts->disable($staff, $this->coverage->actor());
        } catch (StaffRefused $e) {
            return $this->back($request, $staff, 'error', $e->getMessage());
        }

        return $this->back($request, $staff, 'success', $this->translator->get('rbac-admin.staff.disabled'));
    }

    #[RecordAction(name: 'enable', label: 'rbac-admin.actions.enable', permission: self::PERMISSION, toolbar: false)]
    public function enable(DynamicRecord $staff, ServerRequestInterface $request): ResponseInterface
    {
        $staff = $this->fresh($staff);
        if ($staff === null) {
            return new Response(404, ['Content-Type' => 'text/plain; charset=UTF-8'], 'Not found'); // deleted meanwhile
        }
        try {
            $this->accounts->enable($staff, $this->coverage->actor());
        } catch (StaffRefused $e) {
            return $this->back($request, $staff, 'error', $e->getMessage());
        }

        return $this->back($request, $staff, 'success', $this->translator->get('rbac-admin.staff.enabled'));
    }

    /** The account as stored now; null when it was deleted meanwhile (StaffAccounts changes only `active` and the epoch of it). */
    private function fresh(DynamicRecord $staff): ?DynamicRecord
    {
        return $this->dm->findType(StaffManagers::TYPE, (string) $staff->getId());
    }

    /** The rules on the record as the action would save it: the refusal's message, or null. */
    private function refused(DynamicRecord $staff): ?string
    {
        try {
            $this->accounts->checkSave($staff, $this->coverage->actor());
        } catch (StaffRefused $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * Queues an invite or reset mail: null when queued, else the message why not (logged).
     *
     * @param 'invite'|'reset' $mail
     */
    private function send(string $mail, DynamicRecord $staff, string $failed): ?string
    {
        try {
            $mail === 'invite' ? $this->accounts->invite($staff) : $this->accounts->resetLink($staff);
        } catch (StaffRefused $e) { // a password already, disabled, no sign-in, no app.url
            return $e->getMessage();
        } catch (\Throwable $e) {
            error_log("rbac: {$mail} mail not queued for staff:{$staff->getId()}: {$e->getMessage()}");

            return $this->translator->get('rbac-admin.' . $failed);
        }

        return null;
    }

    private function back(ServerRequestInterface $request, DynamicRecord $staff, string $type, string $message): ResponseInterface
    {
        $session = $request->getAttribute(SessionInterface::class);
        if ($session instanceof SessionInterface) {
            $session->flash($type, $message);
        }
        $prefix = rtrim((string) preg_replace('~/' . StaffManagers::TYPE . '/[^/]+/_action/.*$~', '', $request->getUri()->getPath()), '/');

        return new Response(302, ['Location' => $prefix . '/' . StaffManagers::TYPE . '/' . rawurlencode((string) $staff->getId()) . '/edit']);
    }
}

// The name before 0.69 (preflow/rbac), for one release (legacy-aliases.php).
if (!class_exists('Preflow\\Rbac\\Types\\StaffAdmin', false)) {
    class_alias(StaffAdmin::class, 'Preflow\\Rbac\\Types\\StaffAdmin');
}
