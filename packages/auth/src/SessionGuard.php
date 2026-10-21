<?php

declare(strict_types=1);

namespace Preflow\Auth;

use Preflow\Core\Http\Session\SessionInterface;
use Psr\Http\Message\ServerRequestInterface;

final class SessionGuard implements GuardInterface
{
    /**
     * @param string       $sessionKey where this guard keeps its user id (one key per guard)
     * @param list<string> $otherKeys  other guards' keys: logging out keeps the session while one is set
     */
    public function __construct(
        private readonly UserProviderInterface $provider,
        private readonly PasswordHasherInterface $hasher,
        private readonly string $sessionKey = '_auth_user_id',
        private readonly array $otherKeys = [],
    ) {}

    public function user(ServerRequestInterface $request): ?Authenticatable
    {
        $session = $request->getAttribute(SessionInterface::class);

        if ($session === null) {
            return null;
        }

        $userId = $session->get($this->sessionKey);

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
        $session->set($this->sessionKey, $user->getAuthId());
    }

    public function logout(ServerRequestInterface $request): void
    {
        $session = $request->getAttribute(SessionInterface::class);

        if ($session === null) {
            return;
        }

        // Only this guard's user leaves; a new id either way (fixation). The whole
        // session ends when no other guard still has someone signed in.
        $session->remove($this->sessionKey);
        foreach ($this->otherKeys as $key) {
            if ($session->get($key) !== null) {
                $session->regenerate();
                return;
            }
        }
        $session->invalidate();
    }
}
