<?php

declare(strict_types=1);

namespace Preflow\Members;

use Preflow\Core\Context\RoleView;

/**
 * The member Viewer::member() gives while the site is viewed as a member role (view as role): a
 * member with the role view's roles and no account. Every profile field is empty (null), so a
 * page that shows the member's own data shows empty values, never anybody's real data. It is no
 * record: nothing can be set on it, and the data layer (which saves DynamicRecord values) cannot
 * save it.
 *
 * @since 0.81.0
 */
final class SyntheticMember
{
    public function __construct(public readonly RoleView $view) {}

    /** The role view's reserved id, 'preflow:synthetic:{population}:{roles}', which no account has. */
    public function getAuthId(): string
    {
        return $this->view->identity()->getAuthId();
    }

    /** As a record's id is read: the role view's reserved id (getAuthId()). */
    public function getId(): string
    {
        return $this->getAuthId();
    }

    /** 'roles' gives the role view's roles (sorted); every other field null. */
    public function get(string $field): mixed
    {
        return $field === 'roles' ? $this->view->roles : null;
    }

    /** @return array{roles: list<string>} the fields it has: its roles only */
    public function toArray(): array
    {
        return ['roles' => $this->view->roles];
    }

    /** @throws \LogicException always: a synthetic member is read-only */
    public function set(string $field, mixed $value): void
    {
        throw new \LogicException("A synthetic member (view as role) is read-only; '{$field}' cannot be set.");
    }

    /** @throws \LogicException always: a synthetic member is read-only */
    public function remove(string $field): void
    {
        throw new \LogicException("A synthetic member (view as role) is read-only; '{$field}' cannot be removed.");
    }

    /** What a template reads off it: get($name). */
    public function __get(string $name): mixed
    {
        return $this->get($name);
    }

    /** Every field reads (as null), except the names a method answers, which templates call. */
    public function __isset(string $name): bool
    {
        foreach (['', 'get', 'is', 'has'] as $prefix) {
            $method = $prefix === '' ? $name : $prefix . ucfirst($name);
            if (method_exists($this, $method) && (new \ReflectionMethod($this, $method))->getNumberOfRequiredParameters() === 0) {
                return false;
            }
        }

        return true;
    }

    /** @throws \LogicException always: a synthetic member is read-only */
    public function __set(string $name, mixed $value): void
    {
        $this->set($name, $value);
    }

    /** @throws \LogicException always: a synthetic member is read-only */
    public function __unset(string $name): void
    {
        $this->remove($name);
    }
}
