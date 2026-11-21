<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Preflow\Auth\Access\Access;
use Preflow\Core\Context\RoleView;
use Preflow\Folio\FolioText;

/**
 * The whole-page preview's "View as" control: which roles the signed-in editor may view the
 * page as, and the role view a chosen value stands for. Folio only offers what auth answers:
 * the permission site.view_as (rbac declares it) and the roles a resolver lists (Access::roles()).
 * Whoever holds the permission may view as every member and staff role, also one with more
 * rights than their own (owner ruling, 0.81: roles are not compared). Without the
 * permission, or without it declared, there is nothing to choose.
 *
 * A value is "{population}:{role}": "member:gold", "staff:editor"; '' is an anonymous visitor.
 *
 * @since 0.81.0
 */
final class ViewAs
{
    /** The permission to view the site as a role: core's one name for it (rbac declares it). */
    public const string PERMISSION = \Preflow\Core\Context\Preview\PreviewGate::VIEW_AS;

    /**
     * @param (\Closure(string $population, string $role): string)|null $label a role's label as
     *        the admin reads it (the role's name when null)
     */
    public function __construct(
        private readonly ?Access $access,
        private readonly ?\Closure $label = null,
    ) {}

    /** Whether the signed-in editor may view pages as a role at all (site.view_as, declared and held). */
    public function allowed(): bool
    {
        return $this->access !== null
            && $this->access->user() !== null
            && $this->access->registry()->isDeclared(self::PERMISSION)
            && $this->access->can(self::PERMISSION);
    }

    /**
     * The choices, anonymous visitor first, then every member role and every staff role, each
     * group by name; only the visitor when the editor may not view as roles.
     *
     * @return list<array{value: string, label: string, group: string}>
     */
    public function options(): array
    {
        $options = [['value' => '', 'label' => FolioText::get('folio.preview.anonymous'), 'group' => '']];
        if (!$this->allowed()) {
            return $options;
        }
        foreach (['member' => 'members', 'staff' => 'staff'] as $population => $listed) {
            $roles = $this->access?->roles($listed) ?? [];
            sort($roles, SORT_STRING);
            foreach ($roles as $role) {
                $options[] = [
                    'value' => $population . ':' . $role,
                    'label' => $this->label !== null ? ($this->label)($listed, $role) : $role,
                    'group' => FolioText::get('folio.preview.' . ($population === 'staff' ? 'staff_roles' : 'member_roles')),
                ];
            }
        }

        return $options;
    }

    /**
     * The role view $value names, when the editor may view as roles; null for '' (an anonymous
     * visitor). Only that one role is checked: it must be a role the resolver lists for its
     * population.
     *
     * @throws \DomainException when $value is not a choice the editor has (a role they may not view as, a malformed value)
     */
    public function roleView(string $value): ?RoleView
    {
        if ($value === '') {
            return null;
        }
        $parts = explode(':', $value, 2);
        $listed = ['member' => 'members', 'staff' => 'staff'][$parts[0]] ?? null;
        $role = $parts[1] ?? '';
        if ($listed === null || $role === '' || str_contains($role, ',') || !$this->allowed()
            || !in_array($role, $this->access?->roles($listed) ?? [], true)) {
            throw new \DomainException("View as '{$value}' is not offered to this editor.");
        }
        return new RoleView($parts[0], [$role]);
    }
}
