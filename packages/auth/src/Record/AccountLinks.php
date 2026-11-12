<?php

declare(strict_types=1);

namespace Preflow\Auth\Record;

use Preflow\Auth\PasswordHasherInterface;
use Preflow\Auth\Remember\RememberMe;
use Preflow\Auth\Tokens\Tokens;
use Preflow\Auth\UserProviderInterface;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;

/**
 * Password links of a guard's record accounts (RecordUserProvider): the link's token, the account
 * it is for, the new password. Which purposes there are, how long a link is valid and how it
 * reaches the account (a mail) are the caller's (Folio's StaffPasswords: folio.invite, folio.reset).
 *
 * A link's token (Tokens) has the caller's purpose and the subject "{type}:{id}". A link works only
 * while its account resolves through its record provider (an inactive account's link is refused).
 * A new password ends the account's other sessions (the epoch field goes up) and its remember-me
 * rows for the guard; the caller voids its links (revoke).
 */
final class AccountLinks
{
    /**
     * @param list<RecordUserProvider> $providers the guard's record providers
     * @param UserProviderInterface $users        the guard's provider (a chain asks the provider that claims an address)
     */
    public function __construct(
        private readonly Tokens $tokens,
        private readonly ?RememberMe $remember,
        private readonly PasswordHasherInterface $hasher,
        private readonly DataManager $dm,
        private readonly UserProviderInterface $users,
        private readonly array $providers,
        private readonly string $guard,
    ) {}

    /** The token subject of an account: "{type}:{id}". */
    public static function subject(DynamicRecord $user): string
    {
        return $user->getType()->key . ':' . $user->getId();
    }

    /** Whether the guard has record accounts at all. */
    public function enabled(): bool
    {
        return $this->providers !== [];
    }

    /** The record provider of the account's type, or null when the type is not one of the guard's. */
    public function provider(DynamicRecord $user): ?RecordUserProvider
    {
        foreach ($this->providers as $provider) {
            if ($provider->type() === $user->getType()->key) {
                return $provider;
            }
        }

        return null;
    }

    /** A new link token for the account, valid for $ttlSeconds. Earlier links stay (revoke voids them). */
    public function issue(DynamicRecord $user, string $purpose, int $ttlSeconds): string
    {
        $this->require($user);

        return $this->tokens->issue($purpose, self::subject($user), $ttlSeconds);
    }

    /** Uses up a link token: its account while that is active, else null (unknown, expired, used, other purpose). */
    public function consume(string $token, string $purpose): ?DynamicRecord
    {
        if ($token === '') {
            return null;
        }
        $subject = $this->tokens->consume($token, $purpose);
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

    /** Voids the account's links of these purposes. */
    public function revoke(DynamicRecord $user, string ...$purposes): void
    {
        foreach ($purposes as $purpose) {
            $this->tokens->revokeAll($purpose, self::subject($user));
        }
    }

    /**
     * Hashes and saves the password, bumps the epoch, revokes the account's remember-me rows for the
     * guard. Saved on the record as stored now (read again after the hash), with only the password
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

        return true;
    }

    /** Ends the account's remember-me logins for the guard. */
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

    /**
     * The active record account the guard signs in with this e-mail, or null: unknown, not a
     * record (a config user), an inactive record.
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

    /** The account as the guard signs it in (read afresh, so its epoch is the saved one); null when it is inactive. */
    public function account(DynamicRecord $user): ?RecordUser
    {
        $found = $this->require($user)->findById((string) $user->getId());

        return $found instanceof RecordUser ? $found : null;
    }

    private function require(DynamicRecord $user): RecordUserProvider
    {
        return $this->provider($user)
            ?? throw new \InvalidArgumentException("Type \"{$user->getType()->key}\" is not a user type of the {$this->guard} guard.");
    }
}
