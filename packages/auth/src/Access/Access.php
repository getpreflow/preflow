<?php

declare(strict_types=1);

namespace Preflow\Auth\Access;

use Preflow\Auth\Authenticatable;
use Preflow\Auth\AuthManager;
use Preflow\Auth\SyntheticUser;
use Preflow\Core\Context\RoleView;

/**
 * The one permission question: can('page.publish'). It asks about the user
 * signed in for this request, in its population (staff or members, per guard),
 * and lets the installed resolver answer. Undeclared permissions throw in
 * debug and are denied (logged once) in production; so are
 * those of a disabled extension.
 *
 * A role view (view as role: the user is a SyntheticUser) has no account and no guard: it is
 * answered for its roles in its own population (populationOfRoleView()), subject included,
 * through the resolver's AllowsRoles (since 0.81.0). No account is read and the resolver's
 * allows() is never asked for it, so a role view never gets more than its roles allow; a
 * resolver without AllowsRoles grants it nothing.
 */
final class Access
{
    private PermissionResolver $resolver;

    /** @var array<string, true> undeclared permissions already logged this request */
    private array $logged = [];

    /** @var array<string, true> guards marked as staff by a package (Folio) */
    private array $staffGuards = [];

    /**
     * @param array<string, string> $populations guard name => population ('staff', 'members' or the app's own name) from config/auth.php
     */
    public function __construct(
        private readonly ?AuthManager $auth,
        private readonly PermissionRegistry $registry,
        private readonly bool $debug,
        private readonly array $populations,
    ) {
        $this->resolver = new DefaultResolver();
    }

    /**
     * The names of the roles defined for a population, when the resolver lists them (ListsRoles:
     * preflow/roles, preflow/rbac); null when it has no roles to list (the default resolver).
     *
     * @return list<string>|null
     */
    public function roles(string $population): ?array
    {
        return $this->resolver instanceof ListsRoles ? $this->resolver->roles($population) : null;
    }

    public function setResolver(PermissionResolver $resolver): void
    {
        $this->resolver = $resolver;
    }

    /** The resolver answering now: DefaultResolver until a package sets one (a package may wrap it, or leave another's in place). */
    public function resolver(): PermissionResolver
    {
        return $this->resolver;
    }

    /** A package that authenticates staff with this guard (Folio) marks it; config still wins. */
    public function markStaffGuard(string $guard): void
    {
        $this->staffGuards[$guard] = true;
    }

    /**
     * A guard declared 'staff' is staff; one declared any other population ('members', or a name of
     * the app's own, such as the starter's 'employers') counts as members, and a package's mark never
     * makes it staff. A guard without a population is staff when a package marked it, else members.
     *
     * @return 'staff'|'members'
     */
    public function populationOf(string $guard): string
    {
        $configured = $this->declaredPopulation($guard);
        if ($configured !== null) {
            return $configured === 'staff' ? 'staff' : 'members';
        }

        return isset($this->staffGuards[$guard]) ? 'staff' : 'members';
    }

    /** The population config/auth.php declares for the guard (guards.<name>.population), as written; null when none. */
    public function declaredPopulation(string $guard): ?string
    {
        $configured = $this->populations[$guard] ?? null;

        return is_string($configured) && $configured !== '' ? $configured : null;
    }

    /**
     * The population a role view (view as role) counts in: 'staff' for a RoleView of the staff
     * population, 'members' for any other ('member', 'members'), as populationOf() counts a guard.
     *
     * @since 0.81.0
     *
     * @return 'staff'|'members'
     */
    public static function populationOfRoleView(RoleView $view): string
    {
        return $view->population === 'staff' ? 'staff' : 'members';
    }

    /**
     * Whether a role view (view as role) counts as signed in with $guard: its population is the
     * guard's, and when it names the guard of the person viewing as it (RoleView::$guard) and that
     * guard is of the same population, $guard is that guard. So an editor of the guard 'staff'
     * viewing as a staff role passes 'staff' only, never a second staff guard ('ops') they have no
     * account in; a member role view still passes every members guard.
     *
     * @since 0.81.0
     */
    public function roleViewCounts(RoleView $view, string $guard): bool
    {
        $population = self::populationOfRoleView($view);
        if ($population !== $this->populationOf($guard)) {
            return false;
        }

        return $view->guard === null || $view->guard === $guard || $this->populationOf($view->guard) !== $population;
    }

    /**
     * The population ('staff'|'members') of the user signed in for this request; null when nobody is, or no auth is configured.
     * A role view's is its RoleView's (populationOfRoleView()), never a guard's.
     */
    public function population(): ?string
    {
        $user = $this->auth?->user();
        if ($user instanceof SyntheticUser) {
            return self::populationOfRoleView($user->view);
        }
        $guard = $this->auth?->guardName();

        return $user === null || $guard === null ? null : $this->populationOf($guard);
    }

    public function can(string $permission, ?object $subject = null): bool
    {
        $early = $this->earlyAnswer($permission);
        if ($early !== null) {
            return $early;
        }
        $user = $this->auth?->user();
        if ($user === null) {
            return false;
        }
        if ($user instanceof SyntheticUser) {
            return $this->grants($user->view, $permission, $subject);
        }

        return $this->resolver->allows($user, $this->populationOf($this->auth->guardName() ?? ''), $permission, $subject);
    }

    /**
     * Like can(), but any user signed in for this request counts, across guards (a staff
     * editor who is also a signed-in member passes by the member's permission).
     */
    public function canAny(string $permission, ?object $subject = null): bool
    {
        $early = $this->earlyAnswer($permission);
        if ($early !== null) {
            return $early;
        }
        foreach ($this->auth?->users() ?? [] as $guard => $user) {
            if ($user instanceof SyntheticUser ? $this->grants($user->view, $permission, $subject) : $this->resolver->allows($user, $this->populationOf((string) $guard), $permission, $subject)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the user signed in for this request may view the site as $view (view as role) by
     * their roles: a role view of the members' population always (a visitor's roles); a staff
     * role view only when the resolver answers for roles (AllowsRoles) and its roles grant nothing
     * the user may not do. A resolver that compares roles (ComparesRoles) says so by its own rules,
     * on any subject; without it, only roles the user holds themselves count. So nobody sees the
     * site with more rights than their own (an editor never as an admin). Nobody signed in, or a
     * role view itself, may not. Whether they may view as roles at all is the permission the
     * caller asks (site.view_as with rbac).
     *
     * Not used by Folio's View as, nor by the preview token's checks: the owner ruled (0.81) that
     * whoever holds site.view_as may view as any role, also one with more rights. Kept for an app
     * that wants the stricter rule for its own picker.
     *
     * @since 0.81.0
     */
    public function mayViewAs(RoleView $view): bool
    {
        $user = $this->auth?->user();
        if ($user === null || $user instanceof SyntheticUser) {
            return false;
        }
        if (self::populationOfRoleView($view) === 'members') {
            return true;
        }
        if (!$this->resolver instanceof AllowsRoles) {
            return false;
        }
        if ($this->resolver instanceof ComparesRoles) {
            return $this->resolver->rolesWithin($view->roles, 'staff', $user);
        }
        $own = array_map('strval', array_filter($user->getRoles(), static fn (mixed $role): bool => is_string($role) || is_int($role)));

        return array_diff($view->roles, $own) === [];
    }

    /** Whether $view's roles allow $permission on $subject (AllowsRoles); false when the resolver cannot say. */
    private function grants(RoleView $view, string $permission, ?object $subject): bool
    {
        return $this->resolver instanceof AllowsRoles
            && $this->resolver->allowsRoles($view->roles, self::populationOfRoleView($view), $permission, $subject);
    }

    /** The answer that holds whoever is signed in (disabled, no auth, undeclared), or null to ask the user. */
    private function earlyAnswer(string $permission): ?bool
    {
        $disabled = $this->registry->disabledReason($permission);
        if ($disabled !== null) { // before the undeclared check; a disabled name never throws, not even in debug
            if (!isset($this->logged[$permission])) {
                $this->logged[$permission] = true;
                error_log("preflow/auth: permission {$permission} belongs to a disabled {$disabled}");
            }

            return false;
        }
        if ($this->auth === null) {
            return true; // no auth configured: nothing to restrict (e.g. Folio without login)
        }
        if (!$this->registry->isDeclared($permission)) {
            if ($this->debug) {
                throw UndeclaredPermission::named($permission);
            }
            if (!isset($this->logged[$permission])) {
                $this->logged[$permission] = true;
                error_log("preflow/auth: undeclared permission \"{$permission}\" denied");
            }

            return false;
        }

        return null;
    }

    /** The user signed in for this request; null when nobody is, or no auth is configured. */
    public function user(): ?Authenticatable
    {
        return $this->auth?->user();
    }

    public function registry(): PermissionRegistry
    {
        return $this->registry;
    }
}
