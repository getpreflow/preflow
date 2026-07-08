<?php

declare(strict_types=1);

namespace Preflow\Auth;

/**
 * UserProvider over an in-config list of admins, each
 * ['email' => , 'password_hash' => , 'roles' => []]. The auth id is the email.
 * No password check here — SessionGuard verifies via PasswordHasherInterface.
 */
final class ConfigUserProvider implements UserProviderInterface
{
    /** @var array<int, array<string, mixed>> */
    private array $users;

    /** @param array<int, array<string, mixed>> $users */
    public function __construct(array $users)
    {
        $this->users = array_values($users);
    }

    public function findById(string $id): ?Authenticatable
    {
        foreach ($this->users as $user) {
            if ((string) ($user['email'] ?? '') === $id && $id !== '') {
                return $this->toUser($user);
            }
        }
        return null;
    }

    public function findByCredentials(array $credentials): ?Authenticatable
    {
        $email = isset($credentials['email']) ? (string) $credentials['email'] : '';
        if ($email === '') {
            return null;
        }
        foreach ($this->users as $user) {
            if ((string) ($user['email'] ?? '') === $email) {
                return $this->toUser($user);
            }
        }
        return null;
    }

    /** @param array<string, mixed> $user */
    private function toUser(array $user): ArrayUser
    {
        $email = (string) ($user['email'] ?? '');
        return new ArrayUser(
            $email,
            $email,
            (string) ($user['password_hash'] ?? ''),
            array_values(array_filter((array) ($user['roles'] ?? []), 'is_string')),
        );
    }
}
