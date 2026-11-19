<?php

declare(strict_types=1);

namespace Preflow\Core\Context;

use Preflow\Core\Identity;

/**
 * Who is looking: the identities signed in, by guard, and which guard is the primary one; or no
 * one (anonymous); or a role viewed as (synthetic).
 *
 * @since 0.81.0
 */
final readonly class Viewer
{
    /**
     * @param array<string, Identity> $identities by guard
     * @param string|null $primaryGuard the guard of primary(); null: the first identity given
     * @throws \InvalidArgumentException when an identity is not an Identity or $primaryGuard names none of them
     */
    public function __construct(
        public array $identities = [],
        public ?string $primaryGuard = null,
        public ?RoleView $roleView = null,
    ) {
        foreach ($identities as $guard => $identity) {
            if (!is_string($guard) || !$identity instanceof Identity) {
                throw new \InvalidArgumentException('A viewer holds Identity values keyed by guard name.');
            }
        }
        if ($primaryGuard !== null && !isset($identities[$primaryGuard])) {
            throw new \InvalidArgumentException("The primary guard '{$primaryGuard}' has no identity.");
        }
    }

    /** No one signed in. */
    public static function anonymous(): self
    {
        return new self();
    }

    /** Someone viewing as $view's roles: its synthetic identity under the population's name. */
    public static function synthetic(RoleView $view): self
    {
        return new self([$view->population => $view->identity()], $view->population, $view);
    }

    /** The primary guard's identity, or null for an anonymous viewer. */
    public function primary(): ?Identity
    {
        if ($this->primaryGuard !== null) {
            return $this->identities[$this->primaryGuard];
        }

        return $this->identities === [] ? null : $this->identities[array_key_first($this->identities)];
    }

    /** The identity signed in with $guard, or null. */
    public function for(string $guard): ?Identity
    {
        return $this->identities[$guard] ?? null;
    }

    public function isAnonymous(): bool
    {
        return $this->identities === [];
    }

    /** Viewing as a role (RoleView), not as a real account. */
    public function isSynthetic(): bool
    {
        return $this->roleView !== null;
    }

    /**
     * A stable string for memo keys: 'anon', 'role:{population}:{roles}' for a synthetic viewer,
     * else 'id:{guard}:{id}' per identity, sorted by guard and joined by ';' (one identity: just
     * 'id:{guard}:{id}').
     */
    public function key(): string
    {
        if ($this->roleView !== null) {
            return 'role:' . $this->roleView->population . ':' . implode(',', $this->roleView->roles);
        }
        if ($this->identities === []) {
            return 'anon';
        }
        $identities = $this->identities;
        ksort($identities, SORT_STRING);
        $parts = [];
        foreach ($identities as $guard => $identity) {
            $parts[] = 'id:' . $guard . ':' . $identity->getAuthId();
        }

        return implode(';', $parts);
    }
}
