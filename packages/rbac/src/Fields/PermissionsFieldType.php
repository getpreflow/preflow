<?php

declare(strict_types=1);

namespace Preflow\Rbac\Fields;

use Preflow\Auth\Access\PermissionRegistry;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldType;
use Preflow\Folio\Http\AdminNav;
use Preflow\Rbac\RoleList;

/**
 * A role's permissions ("rbac_permissions"), in Folio's look: "Everything" (*) as a switch
 * on top, the content types as a matrix (rows by sidebar section, a column per standard
 * verb, "All" = "{type}.*"), every other declared permission as a chip under its group (the
 * group's pattern, if declared, as the group's "All"). The value is a list of names and
 * patterns; anything not declared is shown as unknown and dropped on save. rbac.js dims and
 * locks what "Everything" or an "All" already grants; without it every box stays usable.
 */
final class PermissionsFieldType implements FieldType
{
    private const VERBS = ['view' => 'View', 'create' => 'Create', 'edit' => 'Edit', 'publish' => 'Publish', 'delete' => 'Delete'];

    public function __construct(
        private readonly PermissionRegistry $registry,
        private readonly TypeCatalog $catalog,
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
        $input = static fn (string $value, string $attrs = '') => '<input type="checkbox" name="' . $name . '" value="' . $e($value) . '"' . $attrs
            . (in_array($value, $selected, true) ? ' checked' : '') . '>';
        [$sections, $others] = $this->layout();

        $html = '<fieldset class="form-group rbac-permissions' . ($ctx->errors !== [] ? ' has-error' : '') . '" data-rbac-permissions>'
            . '<legend class="rbac-legend">' . $e($ctx->label ?? 'Permissions') . '</legend>'
            . '<input type="hidden" name="' . $name . '" value="">' // none checked still posts an empty list
            . '<label class="rbac-everything"><span class="rbac-everything-text"><span class="rbac-everything-label">Everything</span>'
            . '<span class="rbac-everything-hint">Grants every permission, now and in future extensions (<code>*</code>)</span></span>'
            . '<input type="checkbox" class="rbac-switch" role="switch" name="' . $name . '" value="*" data-rbac-everything' . (in_array('*', $selected, true) ? ' checked' : '') . '></label>';
        $unknown = array_diff($selected, $this->known());
        if ($unknown !== []) {
            $html .= '<p class="rbac-unknown">Unknown, removed on save: '
                . implode(', ', array_map(static fn (string $p): string => '<code>' . $e($p) . '</code>', $unknown)) . '</p>';
        }

        $html .= '<div class="rbac-rest" data-rbac-rest>';
        if ($sections !== []) {
            $html .= '<div class="folio-table-wrap"><table class="folio-table rbac-matrix"><thead><tr><th scope="col">Type</th>';
            foreach ([...self::VERBS, 'All'] as $verb) {
                $html .= '<th scope="col">' . $verb . '</th>';
            }
            $html .= '</tr></thead><tbody>';
            foreach ($sections as $section => $rows) {
                $html .= "\n" . '<tr class="rbac-section"><th colspan="' . (count(self::VERBS) + 2) . '" scope="colgroup">' . $e($section) . '</th></tr>';
                foreach ($rows as $row) {
                    $label = $e($row['label']);
                    $html .= "\n" . '<tr class="rbac-row" data-rbac-row><th scope="row" title="' . $e($row['key']) . '.*">' . $label . '</th>';
                    foreach (self::VERBS as $action => $verb) {
                        $permission = $row['key'] . '.' . $action;
                        $html .= '<td class="rbac-cell">' . (in_array($permission, $row['names'], true)
                            ? $input($permission, ' title="' . $e($permission) . '" aria-label="' . $label . ': ' . $verb . '"')
                            : '<span class="rbac-none" title="' . $label . ' have no ' . $action . ' permission">–</span>') . '</td>';
                    }
                    $html .= '<td class="rbac-cell rbac-cell-all">' . ($row['pattern'] !== null
                        ? $input($row['pattern'], ' title="' . $e($row['pattern']) . '" aria-label="' . $label . ': All" data-rbac-all')
                        : '<span class="rbac-none">–</span>') . '</td></tr>';
                }
            }
            $html .= '</tbody></table></div>';
        }
        if ($others !== []) {
            $html .= "\n" . '<div class="rbac-others"><h3 class="rbac-others-heading">Other permissions</h3>';
            foreach ($others as $group => $entries) {
                $html .= "\n" . '<div class="rbac-group" data-rbac-group><div class="rbac-group-head"><span class="rbac-group-label">' . $e($group) . '</span>';
                $patterns = $entries['patterns'];
                $all = array_shift($patterns);
                if ($all !== null) {
                    $html .= '<label class="rbac-group-all" title="' . $e($all) . '">' . $input($all, ' data-rbac-all') . ' All</label>';
                }
                $html .= '</div><div class="rbac-chips">';
                $chips = [...array_fill_keys($patterns, ''), ...$entries['names']]; // further patterns of the group: chips named as the pattern
                foreach ($chips as $permission => $chipLabel) {
                    $permission = (string) $permission;
                    $html .= '<label class="rbac-chip" title="' . $e($permission) . '">' . $input($permission)
                        . '<span>' . $e($chipLabel !== '' ? $chipLabel : $permission) . '</span></label>';
                }
                $html .= '</div></div>';
            }
            $html .= '</div>';
        }
        $html .= '</div>';

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
        return ['rbac.css', 'rbac.js'];
    }

    /**
     * The matrix rows (each content type with a declared standard verb: its label, declared
     * verbs and "{type}.*" if declared) by sidebar section label, in sidebar order; and every
     * other declared name by group in declaration order, with the group's patterns: a pattern
     * "x.*" goes to the first group with a name starting "x." (patterns without one to "Other").
     *
     * @return array{0: array<string, list<array{key: string, label: string, names: list<string>, pattern: ?string}>>, 1: array<string, array{patterns: list<string>, names: array<string, string>}>}
     */
    private function layout(): array
    {
        $declared = $this->registry->all();
        $patterns = $this->registry->patterns();
        $taken = [];
        $bySection = [];
        foreach ($this->catalog->all() as $listing) {
            $names = array_values(array_filter(
                array_map(static fn (string $action): string => $listing->key . '.' . $action, array_keys(self::VERBS)),
                static fn (string $permission): bool => isset($declared[$permission]),
            ));
            if ($names === []) {
                continue;
            }
            $pattern = in_array($listing->key . '.*', $patterns, true) ? $listing->key . '.*' : null;
            $bySection[$listing->section][] = ['key' => $listing->key, 'label' => $listing->label, 'names' => $names, 'pattern' => $pattern];
            $taken += array_fill_keys([...$names, ...($pattern !== null ? [$pattern] : [])], true);
        }
        $sections = [];
        foreach (AdminNav::sectionOrder(array_keys($bySection)) as $section) {
            if (isset($bySection[$section])) {
                $sections[AdminNav::sectionLabel($section)] = $bySection[$section];
            }
        }

        $groups = [];
        foreach ($declared as $permission => $meta) {
            if (!isset($taken[$permission])) {
                $groups[$meta['group'] !== '' ? $meta['group'] : 'Other']['names'][(string) $permission] = $meta['label'];
            }
        }
        foreach (array_unique($patterns) as $pattern) {
            if (isset($taken[$pattern])) {
                continue;
            }
            $prefix = substr($pattern, 0, -1);
            $owner = 'Other';
            foreach ($groups as $group => $entries) {
                foreach (array_keys($entries['names'] ?? []) as $permission) {
                    if (str_starts_with((string) $permission, $prefix)) {
                        $owner = $group;
                        break 2;
                    }
                }
            }
            $groups[$owner]['patterns'][] = $pattern;
        }

        return [$sections, array_map(static fn (array $g): array => ['patterns' => $g['patterns'] ?? [], 'names' => $g['names'] ?? []], $groups)];
    }

    /** @return list<string> */
    private function known(): array
    {
        return ['*', ...array_map('strval', array_keys($this->registry->all())), ...$this->registry->patterns()];
    }
}
