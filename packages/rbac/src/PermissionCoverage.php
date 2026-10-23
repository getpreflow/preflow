<?php

declare(strict_types=1);

namespace Preflow\Rbac;

use Preflow\Auth\Access\Access;
use Preflow\Auth\Access\Pattern;

/**
 * Delegation: a permission or pattern may be granted only by someone who holds it. A held
 * pattern covers what it matches as a permission (Pattern::matches): "*" covers everything,
 * "x.*" covers "x.*", "x.anything" and deeper ("x.y.z", "x.y.*") but not "x" or "*", an
 * exact name covers itself. A pattern is covered when any held pattern covers it.
 *
 * held() is what the user signed in for this request holds, their roles' patterns in their
 * population (the same answer as the resolver's). With nobody signed in (the CLI: install
 * seeding admin => ['*'], folio:staff:import-config) it is null and delegation does not
 * apply. The same rule holds for every population's roles: a staff editor managing member
 * roles grants only member permissions their own staff roles cover.
 */
final class PermissionCoverage
{
    public function __construct(
        private readonly Access $access,
        private readonly RbacResolver $resolver,
    ) {}

    /** @param list<string> $held */
    public static function covers(array $held, string $pattern): bool
    {
        foreach ($held as $own) {
            if (Pattern::matches($own, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $held
     * @param list<string> $patterns
     */
    public static function coversAll(array $held, array $patterns): bool
    {
        return self::uncovered($held, $patterns) === [];
    }

    /**
     * @param list<string> $held
     * @param list<string> $patterns
     * @return list<string> the patterns not covered, in their order, each once
     */
    public static function uncovered(array $held, array $patterns): array
    {
        return array_values(array_unique(array_filter($patterns, static fn (string $p): bool => !self::covers($held, $p))));
    }

    /**
     * The patterns the signed-in user holds; null when nobody is signed in (delegation does not apply).
     *
     * @return list<string>|null
     */
    public function held(): ?array
    {
        $user = $this->access->user();
        $population = $this->access->population();
        if ($user === null || $population === null) {
            return null;
        }

        return $this->resolver->patterns($user, $population);
    }
}
