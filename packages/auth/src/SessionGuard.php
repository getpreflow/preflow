<?php

declare(strict_types=1);

namespace Preflow\Auth;

use Preflow\Core\Http\Session\SessionInterface;
use Psr\Http\Message\ServerRequestInterface;

final class SessionGuard implements GuardInterface
{
    private const SESSION_KEY = '_auth_user_id';

    public function __construct(
        private readonly UserProviderInterface $provider,
        private readonly PasswordHasherInterface $hasher,
    ) {}

    public function user(ServerRequestInterface $request): ?Authenticatable
    {
        $session = $request->getAttribute(SessionInterface::class);

        if ($session === null) {
            return null;
        }

        $userId = $session->get(self::SESSION_KEY);

        if ($userId === null) {
            return null;
        }

        return $this->provider->findById($userId);
    }

    public function validate(array $credentials): bool
    {
        $user = $this->provider->findByCredentials($credentials);

        if ($user === null) {
            return false;
        }

        $password = $credentials['password'] ?? '';

        return $this->hasher->verify($password, $user->getPasswordHash());
    }

    public function login(Authenticatable $user, ServerRequestInterface $request): void
    {
        $session = $request->getAttribute(SessionInterface::class);

        if ($session === null) {
            return;
        }

        $session->regenerate();
        $session->set(self::SESSION_KEY, $user->getAuthId());
    }

    public function logout(ServerRequestInterface $request): void
    {
        $session = $request->getAttribute(SessionInterface::class);

        if ($session === null) {
            return;
        }

        $session->invalidate();
    }
}
