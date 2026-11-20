<?php

declare(strict_types=1);

namespace Preflow\Auth;

use Preflow\Core\Context\RoleView;

/**
 * The user AuthManager gives for a role viewer (view as role): someone with the role view's roles
 * and no stored account. Its id is the role view's reserved synthetic id, which no guard
 * resolves; it has no password, and nothing can be set on it.
 *
 * @since 0.81.0
 */
final class SyntheticUser implements Authenticatable
{
    public function __construct(public readonly RoleView $view) {}

    /** 'preflow:synthetic:{population}:{sorted roles joined by ","}' (SyntheticIdentity). */
    public function getAuthId(): string
    {
        return $this->view->identity()->getAuthId();
    }

    /** No password: a role view never signs in. */
    public function getPasswordHash(): string
    {
        return '';
    }

    /** @return list<string> the role view's roles, sorted */
    public function roles(): array
    {
        return $this->view->roles;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return $this->view->roles;
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->view->roles, true);
    }

    /** The role view's population ('staff' or 'member'). */
    public function population(): string
    {
        return $this->view->population;
    }

    /** @throws \LogicException always: a role view's user is read-only */
    public function __set(string $name, mixed $value): void
    {
        throw new \LogicException("A role view's user is read-only; '{$name}' cannot be set.");
    }

    /** @throws \LogicException always: a role view's user is read-only */
    public function __unset(string $name): void
    {
        throw new \LogicException("A role view's user is read-only; '{$name}' cannot be unset.");
    }
}
