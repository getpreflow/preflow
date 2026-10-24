<?php

declare(strict_types=1);

namespace Preflow\Rbac\Types;

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
use Preflow\Folio\Users\StaffPasswords;
use Preflow\Rbac\Messages;
use Preflow\Rbac\PermissionCoverage;
use Preflow\Rbac\RoleStore;
use Preflow\Rbac\StaffManagers;
use Preflow\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Staff accounts in the admin. The form's save and delete run StaffRules (delegation, lock-out,
 * e-mail). A new account has no password and gets an invitation by mail once it is stored (a
 * mail that can't be queued keeps the account and says so). The Account panel offers, to those
 * with staff.edit who may change the account: Send invitation (while no password is set), Send
 * reset link, Disable / Enable. Each action runs StaffRules on the record as it would be saved,
 * so a crafted POST gets the same refusals as the form (an account above you, the last manager).
 * Disabling (the action, or unticking Active in the form) bumps the epoch (live sessions end),
 * forgets remember-me logins and voids the account's invite and reset links; enabling changes
 * nothing else.
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
        private readonly DataManager $dm,
        private readonly PermissionCoverage $coverage,
        private readonly Messages $messages,
        private readonly Container $container,
    ) {}

    #[BeforeSave]
    public function beforeSave(DynamicRecord $staff): void
    {
        $id = (string) $staff->getId();
        $stored = $id === '' ? null : $this->dm->findType(StaffManagers::TYPE, $id);
        $this->rules->beforeSave($staff);
        // Unticking "Active" disables as the action does: a new epoch now, devices and links once saved.
        if ($stored !== null && StaffManagers::active($stored->get('active')) && !StaffManagers::active($staff->get('active'))) {
            self::bumpEpoch($staff);
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
            $this->forgetDevicesAndLinks($staff);
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
                ? $session->flash('success', $this->messages->get('staff.invited', ['email' => (string) $staff->get('email')]))
                : $session->flash('error', $error);
        }
    }

    #[BeforeDelete]
    public function beforeDelete(DynamicRecord $staff): void
    {
        $this->rules->beforeDelete($staff);
    }

    #[FormPanel('Account', position: 'side')]
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

    #[RecordAction(name: 'invite', label: 'Send invitation', permission: self::PERMISSION, toolbar: false)]
    public function invite(DynamicRecord $staff, ServerRequestInterface $request): ResponseInterface
    {
        if (($refused = $this->refused($staff)) !== null) {
            return $this->back($request, $staff, 'error', $refused);
        }
        if ((string) $staff->get('password_hash') !== '') {
            return $this->back($request, $staff, 'error', $this->messages->get('staff.has_password'));
        }
        if (!StaffManagers::active($staff->get('active'))) {
            return $this->back($request, $staff, 'error', $this->messages->get('staff.inactive'));
        }
        $error = $this->send('invite', $staff, 'staff.mail_failed');

        return $error !== null
            ? $this->back($request, $staff, 'error', $error)
            : $this->back($request, $staff, 'success', $this->messages->get('staff.invited', ['email' => (string) $staff->get('email')]));
    }

    #[RecordAction(name: 'reset', label: 'Send reset link', permission: self::PERMISSION, toolbar: false)]
    public function reset(DynamicRecord $staff, ServerRequestInterface $request): ResponseInterface
    {
        if (($refused = $this->refused($staff)) !== null) {
            return $this->back($request, $staff, 'error', $refused);
        }
        if (!StaffManagers::active($staff->get('active'))) {
            return $this->back($request, $staff, 'error', $this->messages->get('staff.inactive'));
        }
        $error = $this->send('reset', $staff, 'staff.mail_failed');

        return $error !== null
            ? $this->back($request, $staff, 'error', $error)
            : $this->back($request, $staff, 'success', $this->messages->get('staff.reset_sent', ['email' => (string) $staff->get('email')]));
    }

    #[RecordAction(name: 'disable', label: 'Disable', permission: self::PERMISSION, toolbar: false)]
    public function disable(DynamicRecord $staff, ServerRequestInterface $request): ResponseInterface
    {
        $staff->set('active', false);
        if (($refused = $this->refused($staff)) !== null) {
            return $this->back($request, $staff, 'error', $refused);
        }
        self::bumpEpoch($staff);
        $this->dm->saveType($staff, false);
        $this->forgetDevicesAndLinks($staff);

        return $this->back($request, $staff, 'success', $this->messages->get('staff.disabled'));
    }

    #[RecordAction(name: 'enable', label: 'Enable', permission: self::PERMISSION, toolbar: false)]
    public function enable(DynamicRecord $staff, ServerRequestInterface $request): ResponseInterface
    {
        $staff->set('active', true);
        if (($refused = $this->refused($staff)) !== null) {
            return $this->back($request, $staff, 'error', $refused);
        }
        $this->dm->saveType($staff, false);

        return $this->back($request, $staff, 'success', $this->messages->get('staff.enabled'));
    }

    /** A new epoch ends the account's live sessions (for good: enabling it again doesn't bring them back). */
    private static function bumpEpoch(DynamicRecord $staff): void
    {
        $staff->set('auth_epoch', (string) ((int) $staff->get('auth_epoch') + 1));
    }

    /** Ends the account's remember-me logins and voids its invite and reset links. */
    private function forgetDevicesAndLinks(DynamicRecord $staff): void
    {
        $passwords = $this->passwords();
        $passwords?->forgetDevices($staff);
        $passwords?->revokeLinks($staff);
    }

    /** StaffRules on the record as the action would save it: the refusal's message, or null. */
    private function refused(DynamicRecord $staff): ?string
    {
        try {
            $this->rules->beforeSave($staff);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                foreach ((array) $messages as $message) {
                    return (string) $message;
                }
            }

            return $this->messages->get('delegation.account');
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
        $passwords = $this->passwords();
        if ($passwords === null || !$passwords->enabled()) {
            return $this->messages->get('staff.no_sign_in');
        }
        try {
            $mail === 'invite' ? $passwords->invite($staff) : $passwords->sendReset($staff);
        } catch (\InvalidArgumentException) { // staff isn't a user type of the default guard
            return $this->messages->get('staff.no_sign_in');
        } catch (\Throwable $e) {
            error_log("rbac: {$mail} mail not queued for staff:{$staff->getId()}: {$e->getMessage()}");

            return $this->messages->get($failed);
        }

        return null;
    }

    /** Folio's passwords service; null when the site has no auth set up. */
    private function passwords(): ?StaffPasswords
    {
        try {
            return $this->container->get(StaffPasswords::class);
        } catch (\Throwable) {
            return null;
        }
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
