<?php

declare(strict_types=1);

namespace Preflow\Auth;

use Psr\Http\Message\ServerRequestInterface;

/**
 * What AuthManager::guard() hands out inside a role view (view as role), for every guard name: the
 * session or token the request carries belongs to the editor viewing as the role, so no guard may
 * read it there. The role view's own guard name (AuthManager::roleViewGuard()) gives its
 * SyntheticUser; a configured guard's name gives nobody (the role view did not sign in with it).
 * Nothing validates, signs in or signs out (a LogicException), as SessionGuard refuses it there.
 *
 * @since 0.81.0
 */
final class RoleViewGuard implements GuardInterface
{
    /** @internal AuthManager::guard() */
    public function __construct(private readonly ?SyntheticUser $user) {}

    public function user(ServerRequestInterface $request): ?Authenticatable
    {
        return $this->user;
    }

    /** Never: no password is checked inside a role view. */
    public function validate(array $credentials): bool
    {
        return false;
    }

    /**
     * @param bool $remember as SessionGuard::login() takes it, so its callers reach the refusal
     * @throws \LogicException always: a role view never signs anyone in
     */
    public function login(Authenticatable $user, ServerRequestInterface $request, bool $remember = false): void
    {
        throw new \LogicException('No guard signs in inside a role view (view as role): the session the request carries belongs to the editor viewing as the role.');
    }

    /** @throws \LogicException always: a role view never signs anyone out */
    public function logout(ServerRequestInterface $request): void
    {
        throw new \LogicException('No guard signs out inside a role view (view as role): the session the request carries belongs to the editor viewing as the role.');
    }
}
