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

    /**
     * What a template reads off a user: 'id' (getAuthId()), 'roles' and 'population' from the role
     * view, null for every other field (a role view has no name, email or profile), so a page that
     * greets its user renders in a preview, also with strict variables.
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            'id' => $this->getAuthId(),
            'roles' => $this->view->roles,
            'population' => $this->view->population,
            default => null,
        };
    }

    /** Every field reads (as null when the role view has none), except this class's method names, which templates call. */
    public function __isset(string $name): bool
    {
        return !method_exists($this, $name);
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
