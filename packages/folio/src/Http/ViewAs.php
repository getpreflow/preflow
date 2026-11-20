<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Preflow\Auth\Access\Access;
use Preflow\Core\Context\RoleView;
use Preflow\Folio\FolioText;

/**
 * The whole-page preview's "View as" control: which roles the signed-in editor may view the
 * page as, and the role view a chosen value stands for. Folio only offers what auth answers:
 * the permission site.view_as (rbac declares it), the roles a resolver lists (Access::roles())
 * and Access::mayViewAs() (every member role; a staff role only with no more rights than the
 * editor's own). Without the permission, or without it declared, there is nothing to choose.
 *
 * A value is "{population}:{role}": "member:gold", "staff:editor"; '' is an anonymous visitor.
 *
 * @since 0.81.0
 */
final class ViewAs
{
    public const string PERMISSION = 'site.view_as';

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
     * The choices, anonymous visitor first, then the member roles and the staff roles the editor
     * may view as, each group by name; only the visitor when the editor may not view as roles.
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
                if ($this->access?->mayViewAs(new RoleView($population, [$role])) === true) {
                    $options[] = [
                        'value' => $population . ':' . $role,
                        'label' => $this->label !== null ? ($this->label)($listed, $role) : $role,
                        'group' => FolioText::get('folio.preview.' . ($population === 'staff' ? 'staff_roles' : 'member_roles')),
                    ];
                }
            }
        }

        return $options;
    }

    /**
     * The role view $value names, when the editor may view as it; null for '' (an anonymous
     * visitor).
     *
     * @throws \DomainException when $value is not a choice the editor has (a role they may not view as, a malformed value)
     */
    public function roleView(string $value): ?RoleView
    {
        if ($value === '') {
            return null;
        }
        if (!in_array($value, array_column($this->options(), 'value'), true)) {
            throw new \DomainException("View as '{$value}' is not offered to this editor.");
        }
        [$population, $role] = explode(':', $value, 2);

        return new RoleView($population, [$role]);
    }
}
