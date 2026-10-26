<?php

declare(strict_types=1);

namespace Preflow\Folio\Users;

use Preflow\Auth\PasswordHasherInterface;
use Preflow\Auth\Remember\RememberMe;
use Preflow\Auth\Tokens\Tokens;
use Preflow\Auth\Record\RecordUser;
use Preflow\Auth\Record\RecordUserProvider;
use Preflow\Auth\UserProviderInterface;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;

/**
 * Passwords of the Folio accounts that are records (any Folio user type behind the default guard,
 * see RecordProviders): invite and reset links by mail (in the account's interface language), the
 * set-password page's token, the new password. Generic, so Folio needs no user package; rbac's staff actions call it.
 *
 * A link's token (preflow/auth Tokens) has the purpose folio.invite (7 days) or folio.reset
 * (1 hour) and the subject "{type}:{id}". A link works only while its account resolves through
 * its record provider (an inactive account's link is refused). A new password ends the account's
 * other sessions (the epoch field goes up), its remember-me rows for the guard and its other links.
 */
final class StaffPasswords
{
    public const string INVITE = 'folio.invite';
    public const string RESET = 'folio.reset';
    public const int INVITE_TTL = 7 * 86400;
    public const int RESET_TTL = 3600;

    private ?StaffMailer $builtMailer = null;

    /**
     * @param list<RecordUserProvider> $providers the default guard's record providers
     * @param \Closure(): StaffMailer $mailer       built on the first mail
     * @param string $passwordUrl                  absolute URL of the set-password page; the token is appended after "/"
     *                                             ('' when app.url is unset: invite and reset throw MissingAppUrl)
     * @param UserPreferences|null $preferences    the recipients' interface languages (mails in theirs)
     */
    public function __construct(
        private readonly Tokens $tokens,
        private readonly ?RememberMe $remember,
        private readonly PasswordHasherInterface $hasher,
        private readonly DataManager $dm,
        private readonly UserProviderInterface $users,
        private readonly array $providers,
        private readonly \Closure $mailer,
        private readonly string $guard,
        private readonly string $passwordUrl,
        private readonly ?UserPreferences $preferences = null,
    ) {}

    /** Whether the default guard has record users at all (else there is no password page). */
    public function enabled(): bool
    {
        return $this->providers !== [];
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
        $subject = $this->subject($user);
        $this->tokens->revokeAll(self::INVITE, $subject);
        $this->mail('invite', $user, $this->tokens->issue(self::INVITE, $subject, self::INVITE_TTL));
    }

    /**
     * Mails a set-password link valid for one hour.
     *
     * @throws MissingAppUrl when app.url is unset (nothing is issued)
     */
    public function sendReset(DynamicRecord $user): void
    {
        $this->requireUrl();
        $this->mail('reset', $user, $this->tokens->issue(self::RESET, $this->subject($user), self::RESET_TTL));
    }

    /**
     * The active record account the default guard signs in with this e-mail, or null: unknown, a
     * config user, an inactive record (a chain asks the provider that claims the address).
     */
    public function findActive(string $email): ?DynamicRecord
    {
        $email = mb_strtolower(trim($email));
        if ($email === '' || !$this->enabled()) {
            return null;
        }
        $user = $this->users->findByCredentials(['email' => $email]);

        return $user instanceof RecordUser && $this->provider($user->record()) !== null ? $user->record() : null;
    }

    /** Uses up a reset or invite token: its account while that is active, else null (unknown, expired, used, other purpose). */
    public function consume(string $token): ?DynamicRecord
    {
        if ($token === '') {
            return null;
        }
        $subject = $this->tokens->consume($token, self::RESET) ?? $this->tokens->consume($token, self::INVITE);
        if ($subject === null || !str_contains($subject, ':')) {
            return null;
        }
        [$type, $id] = explode(':', $subject, 2);
        foreach ($this->providers as $provider) {
            if ($provider->type() === $type) {
                $user = $provider->findById($id);

                return $user instanceof RecordUser ? $user->record() : null;
            }
        }

        return null;
    }

    /**
     * Hashes and saves the password, bumps the epoch, revokes the account's remember-me rows and
     * links. Saved on the record as stored now (read again after the hash), with only the password
     * and epoch changed: a stale copy must not write back a Disable or a role change made meanwhile.
     * False, with nothing saved, when the account no longer resolves (disabled or gone).
     */
    public function setPassword(DynamicRecord $user, string $password): bool
    {
        $provider = $this->require($user);
        $fields = $provider->fields();
        $hash = $this->hasher->hash($password);
        $fresh = $provider->findById((string) $user->getId());
        if (!$fresh instanceof RecordUser) {
            return false;
        }
        $record = $fresh->record();
        $record->set($fields['password'], $hash);
        if (isset($record->getType()->fields[$fields['epoch']])) {
            $record->set($fields['epoch'], (string) ((int) $record->get($fields['epoch']) + 1));
        }
        $this->dm->saveType($record, false);

        $this->forgetDevices($record);
        $this->revokeLinks($record);

        return true;
    }

    /** Voids the account's invite and reset links (a disabled account's links must not work once it is enabled again). */
    public function revokeLinks(DynamicRecord $user): void
    {
        $this->tokens->revokeAll(self::INVITE, $this->subject($user));
        $this->tokens->revokeAll(self::RESET, $this->subject($user));
    }

    /** Ends the account's remember-me logins for the default guard. */
    public function forgetDevices(DynamicRecord $user): void
    {
        $this->remember?->revokeAll($this->guard, (string) $user->getId());
    }

    /** Whether $password is the account's current one (an account without a password has none). */
    public function verify(DynamicRecord $user, string $password): bool
    {
        $hash = (string) $user->get($this->require($user)->fields()['password']);
        if ($hash === '') {
            $this->hasher->hash($password); // the same cost as a real check

            return false;
        }

        return $this->hasher->verify($password, $hash);
    }

    /** The account as the guard signs it in (read afresh, so its epoch is the saved one); null when it is inactive. */
    public function account(DynamicRecord $user): ?RecordUser
    {
        $found = $this->require($user)->findById((string) $user->getId());

        return $found instanceof RecordUser ? $found : null;
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
            error_log('folio: last sign-in not saved for account ' . $this->subject($record));
        }
    }

    private function mail(string $mail, DynamicRecord $user, string $token): void
    {
        $fields = $this->require($user)->fields();
        $to = (string) $user->get($fields['email']);
        $name = isset($user->getType()->fields['name']) ? trim((string) $user->get('name')) : '';
        $locale = $this->preferences?->locale((string) $user->getId()); // the auth id of a record user
        ($this->builtMailer ??= ($this->mailer)())->sendLater($mail, $to, $name !== '' ? $name : $to, $this->passwordUrl . '/' . $token, $this->subject($user), $locale);
    }

    private function requireUrl(): void
    {
        if (!$this->canMail()) {
            throw new MissingAppUrl();
        }
    }

    private function subject(DynamicRecord $user): string
    {
        return $user->getType()->key . ':' . $user->getId();
    }

    private function provider(DynamicRecord $user): ?RecordUserProvider
    {
        foreach ($this->providers as $provider) {
            if ($provider->type() === $user->getType()->key) {
                return $provider;
            }
        }

        return null;
    }

    private function require(DynamicRecord $user): RecordUserProvider
    {
        return $this->provider($user)
            ?? throw new \InvalidArgumentException("Type \"{$user->getType()->key}\" is not a user type of the default guard.");
    }
}
