<?php

declare(strict_types=1);

namespace Preflow\Core\Context;

use Preflow\Core\Identity;

/**
 * Who is looking: the identities signed in, by guard, and which guard is the primary one; or no
 * one (anonymous); or a role viewed as (synthetic, built only by Viewer::synthetic()).
 *
 * Safe to log and serialize: a dump (var_dump, print_r) shows each identity as its id only, and
 * a serialized viewer keeps guard, id and the role view; it comes back holding IdentityId values
 * (the id only) instead of the user records it held. json_encode() gives guard => id, the
 * primary guard and the role view's population and roles.
 *
 * @since 0.81.0
 */
final readonly class Viewer implements \JsonSerializable
{
    /**
     * @param array<string, Identity> $identities by guard
     * @param string|null $primaryGuard the guard of primary(); null: the first guard by name
     * @param RoleView|null $roleView only through Viewer::synthetic()
     * @throws \InvalidArgumentException when an identity is not an Identity, $primaryGuard names
     *         none of them, a synthetic identity (or a reserved id) comes without its RoleView, or
     *         a RoleView comes without exactly its own synthetic identity
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
        if ($roleView !== null) {
            $own = $identities[$roleView->population] ?? null;
            if (count($identities) !== 1 || !$own instanceof SyntheticIdentity || $own->getAuthId() !== $roleView->identity()->getAuthId()) {
                throw new \InvalidArgumentException('A role viewer holds exactly the synthetic identity of its role view; build it with Viewer::synthetic().');
            }

            return;
        }
        foreach ($identities as $identity) {
            if ($identity instanceof SyntheticIdentity || str_starts_with($identity->getAuthId(), SyntheticIdentity::PREFIX)) {
                throw new \InvalidArgumentException('A synthetic identity belongs to a role viewer; build it with Viewer::synthetic().');
            }
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

    /** The primary guard's identity; without a primary guard the first guard by name; null when anonymous. */
    public function primary(): ?Identity
    {
        $guard = $this->primaryGuardOrFirst();

        return $guard === null ? null : $this->identities[$guard];
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
     * A stable string for memo keys: 'anon'; 'role:{population}:{roles}' for a synthetic viewer;
     * 'id:{guard}:{id}' for one identity; with several, each 'id:{guard}:{id}' sorted by guard and
     * joined by ';', then '@{the guard primary() reads}' ('id:members:7;id:web:42@web').
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
        $key = implode(';', $parts);

        return count($parts) > 1 ? $key . '@' . $this->primaryGuardOrFirst() : $key;
    }

    /** @return array{identities: array<string, string>, primaryGuard: ?string, roleView: ?RoleView} guard => id only */
    public function __serialize(): array
    {
        return [
            'identities' => array_map(static fn (Identity $i): string => $i->getAuthId(), $this->identities),
            'primaryGuard' => $this->primaryGuard,
            'roleView' => $this->roleView,
        ];
    }

    /** @param array{identities: array<string, string>, primaryGuard: ?string, roleView: ?RoleView} $data */
    public function __unserialize(array $data): void
    {
        $roleView = $data['roleView'] ?? null;
        $identities = [];
        foreach ((array) ($data['identities'] ?? []) as $guard => $id) {
            $identities[(string) $guard] = $roleView instanceof RoleView ? $roleView->identity() : new IdentityId((string) $id);
        }
        $this->identities = $identities;
        $this->primaryGuard = $data['primaryGuard'] ?? null;
        $this->roleView = $roleView instanceof RoleView ? $roleView : null;
    }

    /** @return array<string, mixed> what var_dump and print_r show: ids, never the user records */
    public function __debugInfo(): array
    {
        return $this->__serialize();
    }

    /** @return array{identities: array<string, string>, primaryGuard: ?string, roleView: ?array{population: string, roles: list<string>}} */
    public function jsonSerialize(): array
    {
        $data = $this->__serialize();
        $data['roleView'] = $this->roleView === null ? null : ['population' => $this->roleView->population, 'roles' => $this->roleView->roles];

        return $data;
    }

    private function primaryGuardOrFirst(): ?string
    {
        if ($this->primaryGuard !== null || $this->identities === []) {
            return $this->primaryGuard;
        }
        $guards = array_map('strval', array_keys($this->identities));
        sort($guards, SORT_STRING);

        return $guards[0];
    }
}
