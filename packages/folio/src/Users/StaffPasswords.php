<?php

declare(strict_types=1);

namespace Preflow\Folio\Users;

use Preflow\Auth\PasswordHasherInterface;
use Preflow\Auth\Remember\RememberMe;
use Preflow\Auth\Tokens\Tokens;
use Preflow\Auth\UserProviderInterface;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;

/**
 * Passwords of the Folio accounts that are records (any Folio user type behind the default guard,
 * see RecordProviders): invite and reset links by mail, the set-password page's token, the new
 * password. Generic, so Folio needs no user package; rbac's staff actions call it.
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
    ) {}

    /** Whether the default guard has record users at all (else there is no password page). */
    public function enabled(): bool
    {
        return $this->providers !== [];
    }

    /** Mails a set-password link valid for 7 days; earlier invite links of the account stop working. */
    public function invite(DynamicRecord $user): void
    {
        $subject = $this->subject($user);
        $this->tokens->revokeAll(self::INVITE, $subject);
        $this->mail('invite', $user, $this->tokens->issue(self::INVITE, $subject, self::INVITE_TTL));
    }

    /** Mails a set-password link valid for one hour. */
    public function sendReset(DynamicRecord $user): void
    {
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

    /** Hashes and saves the password, bumps the epoch, revokes the account's remember-me rows and links. */
    public function setPassword(DynamicRecord $user, string $password): void
    {
        $provider = $this->require($user);
        $fields = $provider->fields();
        $user->set($fields['password'], $this->hasher->hash($password));
        if (isset($user->getType()->fields[$fields['epoch']])) {
            $user->set($fields['epoch'], (string) ((int) $user->get($fields['epoch']) + 1));
        }
        $this->dm->saveType($user, false);

        $id = (string) $user->getId();
        $this->remember?->revokeAll($this->guard, $id);
        $this->tokens->revokeAll(self::INVITE, $this->subject($user));
        $this->tokens->revokeAll(self::RESET, $this->subject($user));
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
        ($this->builtMailer ??= ($this->mailer)())->sendLater($mail, $to, $name !== '' ? $name : $to, $this->passwordUrl . '/' . $token, $this->subject($user));
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
