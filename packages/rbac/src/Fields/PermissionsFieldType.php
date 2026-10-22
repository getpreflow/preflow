<?php

declare(strict_types=1);

namespace Preflow\Rbac\Fields;

use Preflow\Auth\Access\PermissionRegistry;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldType;
use Preflow\Rbac\RoleList;

/**
 * A role's permissions ("rbac_permissions"): checkboxes of the declared permissions by group,
 * each group with its "{group}.*" patterns, and "Everything" (*) on top. The value is a list
 * of names and patterns; anything not declared is shown as unknown and dropped on save.
 */
final class PermissionsFieldType implements FieldType
{
    public function __construct(
        private readonly PermissionRegistry $registry,
    ) {}

    public function key(): string
    {
        return 'rbac_permissions';
    }

    public function renderEditor(FieldContext $ctx): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $selected = RoleList::normalize($ctx->value);
        $name = $e($ctx->name . '[]');
        $box = static fn (string $value, string $label) => '<label class="form-radio"><input type="checkbox" name="' . $name . '" value="' . $e($value) . '"'
            . (in_array($value, $selected, true) ? ' checked' : '') . '> ' . $label . '</label>';

        $html = '<fieldset class="form-group rbac-permissions' . ($ctx->errors !== [] ? ' has-error' : '') . '"><legend>' . $e($ctx->label ?? 'Permissions') . '</legend>'
            . '<input type="hidden" name="' . $name . '" value="">' // none checked still posts an empty list
            . $box('*', 'Everything (<code>*</code>)');
        foreach ($this->groups() as $group => $entries) {
            $html .= "\n" . '<fieldset class="rbac-permission-group"><legend>' . $e($group) . '</legend>';
            foreach ($entries['patterns'] as $pattern) {
                $html .= "\n" . $box($pattern, 'All of ' . $e($group) . ' (<code>' . $e($pattern) . '</code>)');
            }
            foreach ($entries['names'] as $permission => $label) {
                $html .= "\n" . $box($permission, $e($label !== '' ? $label : $permission));
            }
            $html .= "\n" . '</fieldset>';
        }
        $unknown = array_diff($selected, $this->known());
        if ($unknown !== []) {
            $html .= "\n" . '<p class="form-help">Unknown, removed on save: '
                . implode(', ', array_map(static fn (string $p): string => '<code>' . $e($p) . '</code>', $unknown)) . '</p>';
        }
        foreach ($ctx->errors as $error) {
            $html .= '<div class="form-error">' . $e((string) $error) . '</div>';
        }
        if ($ctx->help) {
            $html .= '<div class="form-help">' . $e($ctx->help) . '</div>';
        }

        return $html . '</fieldset>';
    }

    /** @return list<string> only "*", declared names and declared patterns */
    public function normalizeInput(mixed $raw, array $config): array
    {
        return array_values(array_intersect(RoleList::normalize($raw), $this->known()));
    }

    public function toStorage(mixed $value): mixed
    {
        return RoleList::normalize($value);
    }

    /** @return list<string> */
    public function fromStorage(mixed $value): array
    {
        return RoleList::normalize($value);
    }

    public function renderFrontend(mixed $value, array $config): string
    {
        return htmlspecialchars(implode(', ', RoleList::normalize($value)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public function rules(array $config): array
    {
        return [];
    }

    public function assets(): array
    {
        return [];
    }

    /**
     * The declared permissions by group, in declaration order; a pattern "x.*" goes to the
     * first group with a name starting "x." (patterns without one to "Other").
     *
     * @return array<string, array{patterns: list<string>, names: array<string, string>}>
     */
    private function groups(): array
    {
        $groups = [];
        foreach ($this->registry->all() as $permission => $meta) {
            $groups[$meta['group'] !== '' ? $meta['group'] : 'Other']['names'][$permission] = $meta['label'];
        }
        foreach ($this->registry->patterns() as $pattern) {
            $prefix = substr($pattern, 0, -1);
            $owner = 'Other';
            foreach ($groups as $group => $entries) {
                foreach (array_keys($entries['names'] ?? []) as $permission) {
                    if (str_starts_with($permission, $prefix)) {
                        $owner = $group;
                        break 2;
                    }
                }
            }
            $groups[$owner]['patterns'][] = $pattern;
        }

        return array_map(static fn (array $g): array => ['patterns' => array_values(array_unique($g['patterns'] ?? [])), 'names' => $g['names'] ?? []], $groups);
    }

    /** @return list<string> */
    private function known(): array
    {
        return ['*', ...array_map('strval', array_keys($this->registry->all())), ...$this->registry->patterns()];
    }
}
