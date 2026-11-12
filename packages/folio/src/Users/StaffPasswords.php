<?php

declare(strict_types=1);

namespace Preflow\Folio\Users;

use Preflow\Auth\Record\AccountLinks;
use Preflow\Auth\Record\RecordUser;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;

/**
 * Passwords of the Folio accounts that are records (any Folio user type behind the staff guard (StaffGuard),
 * see RecordProviders): invite and reset links by mail (in the account's interface language), the
 * set-password page's token, the new password. Generic, so Folio needs no user package; rbac's staff actions call it.
 *
 * The links, the password, the account lookups are auth's AccountLinks (for the staff guard's record
 * providers); Folio adds which links there are, folio.invite (7 days) and folio.reset (1 hour), the
 * mail and the set-password page. A new password also voids the account's other links.
 */
final class StaffPasswords
{
    public const string INVITE = 'folio.invite';
    public const string RESET = 'folio.reset';
    public const int INVITE_TTL = 7 * 86400;
    public const int RESET_TTL = 3600;

    private ?StaffMailer $builtMailer = null;

    /**
     * @param AccountLinks $links                  the staff guard's record accounts
     * @param \Closure(): StaffMailer $mailer       built on the first mail
     * @param string $passwordUrl                  absolute URL of the set-password page; the token is appended after "/"
     *                                             ('' when app.url is unset: invite and reset throw MissingAppUrl)
     * @param UserPreferences|null $preferences    the recipients' interface languages (mails in theirs)
     */
    public function __construct(
        private readonly AccountLinks $links,
        private readonly DataManager $dm,
        private readonly \Closure $mailer,
        private readonly string $passwordUrl,
        private readonly ?UserPreferences $preferences = null,
    ) {}

    /** Whether the staff guard has record users at all (else there is no password page). */
    public function enabled(): bool
    {
        return $this->links->enabled();
    }

    /** Whether links can be mailed: app.url is set (without it a link would point nowhere). */
    public function canMail(): bool
    {
        return $this->passwordUrl !== '';
    }

    /**
     * Mails a set-password link valid for 7 days; earlier invite links of the account stop working.
     *
     * @throws MissingAppUrl when app.url is unset (nothing is issued or revoked)
     */
    public function invite(DynamicRecord $user): void
    {
        $this->requireUrl();
        $this->links->revoke($user, self::INVITE);
        $this->mail('invite', $user, $this->links->issue($user, self::INVITE, self::INVITE_TTL));
    }

    /**
     * Mails a set-password link valid for one hour.
     *
     * @throws MissingAppUrl when app.url is unset (nothing is issued)
     */
    public function sendReset(DynamicRecord $user): void
    {
        $this->requireUrl();
        $this->mail('reset', $user, $this->links->issue($user, self::RESET, self::RESET_TTL));
    }

    /**
     * The active record account the staff guard signs in with this e-mail, or null: unknown, a
     * config user, an inactive record (a chain asks the provider that claims the address).
     */
    public function findActive(string $email): ?DynamicRecord
    {
        return $this->links->findActive($email);
    }

    /** Uses up a reset or invite token: its account while that is active, else null (unknown, expired, used, other purpose). */
    public function consume(string $token): ?DynamicRecord
    {
        return $this->links->consume($token, self::RESET) ?? $this->links->consume($token, self::INVITE);
    }

    /**
     * Saves the password (AccountLinks::setPassword: the epoch goes up, the account's remember-me
     * rows go) and voids the account's invite and reset links. False, with nothing saved, when the
     * account no longer resolves (disabled or gone).
     */
    public function setPassword(DynamicRecord $user, string $password): bool
    {
        if (!$this->links->setPassword($user, $password)) {
            return false;
        }
        $this->revokeLinks($user);

        return true;
    }

    /** Voids the account's invite and reset links (a disabled account's links must not work once it is enabled again). */
    public function revokeLinks(DynamicRecord $user): void
    {
        $this->links->revoke($user, self::INVITE, self::RESET);
    }

    /** Ends the account's remember-me logins for the staff guard. */
    public function forgetDevices(DynamicRecord $user): void
    {
        $this->links->forgetDevices($user);
    }

    /** Whether $password is the account's current one (an account without a password has none). */
    public function verify(DynamicRecord $user, string $password): bool
    {
        return $this->links->verify($user, $password);
    }

    /** The account as the guard signs it in (read afresh, so its epoch is the saved one); null when it is inactive. */
    public function account(DynamicRecord $user): ?RecordUser
    {
        return $this->links->account($user);
    }

    /** Stamps the account's last_login_at (when its type has the field). Best effort: a failure is logged, the sign-in goes on. */
    public function stampLogin(RecordUser $user, string $field = 'last_login_at'): void
    {
        $record = $user->record();
        if (!isset($record->getType()->fields[$field])) {
            return;
        }
        try {
            // On the record as stored now, only the stamp: the copy read at sign-in must not write
            // back a role removed or a Disable made meanwhile.
            $record = $this->dm->findType($record->getType()->key, (string) $record->getId());
            if ($record === null) {
                return; // deleted meanwhile: nothing to stamp
            }
            $record->set($field, (new \DateTimeImmutable())->format(DATE_ATOM));
            $this->dm->saveType($record, false);
        } catch (\Throwable) {
            error_log('folio: last sign-in not saved for account ' . AccountLinks::subject($record));
        }
    }

    private function mail(string $mail, DynamicRecord $user, string $token): void
    {
        $provider = $this->links->provider($user)
            ?? throw new \InvalidArgumentException("Type \"{$user->getType()->key}\" is not a user type of the staff guard.");
        $to = (string) $user->get($provider->fields()['email']);
        $name = isset($user->getType()->fields['name']) ? trim((string) $user->get('name')) : '';
        $locale = $this->preferences?->locale((string) $user->getId()); // the auth id of a record user
        ($this->builtMailer ??= ($this->mailer)())->sendLater($mail, $to, $name !== '' ? $name : $to, $this->passwordUrl . '/' . $token, AccountLinks::subject($user), $locale);
    }

    private function requireUrl(): void
    {
        if (!$this->canMail()) {
            throw new MissingAppUrl();
        }
    }
}
