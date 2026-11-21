<?php

declare(strict_types=1);

namespace Preflow\Auth;

use Psr\Http\Message\ServerRequestInterface;

/**
 * What AuthManager::guard() hands out inside a role view (view as role), for every guard name: the
 * session or token the request carries belongs to the editor viewing as the role, so no guard may
 * read it there. The role view's own guard name (AuthManager::roleViewGuard()) gives its
 * SyntheticUser; a configured guard's name gives nobody (the role view did not sign in with it).
 * Nothing validates, signs in or signs out (PreviewRefusal, a LogicException answered 403 with a
 * text for the editor), as SessionGuard refuses it there. Since 0.81.0 it also stands in inside a
 * render admitted by a preview token as an anonymous visitor (PreviewGrant).
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
     * @throws PreviewRefusal always: a role view never signs anyone in
     */
    public function login(Authenticatable $user, ServerRequestInterface $request, bool $remember = false): void
    {
        throw PreviewRefusal::signIn();
    }

    /** @throws PreviewRefusal always: a role view never signs anyone out */
    public function logout(ServerRequestInterface $request): void
    {
        throw PreviewRefusal::signIn();
    }
}
