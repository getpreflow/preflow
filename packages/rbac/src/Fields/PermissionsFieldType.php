<?php

declare(strict_types=1);

namespace Preflow\Rbac\Fields;

use Preflow\Auth\Access\PermissionRegistry;
use Preflow\Folio\Content\DisplayLabels;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldType;
use Preflow\Folio\Http\AdminNav;
use Preflow\Rbac\Messages;
use Preflow\Rbac\PermissionCoverage;
use Preflow\Rbac\RoleList;

/**
 * A role's permissions ("rbac_permissions"), in Folio's look: "Everything" (*) as a switch
 * on top, the content types as a matrix (rows by sidebar section, a column per standard
 * verb, "All" = "{type}.*"), every other declared permission as a chip under its group (the
 * group's pattern, if declared, as the group's "All"). The value is a list of names and
 * patterns; anything not declared is shown as unknown and dropped on save, a stored declared
 * name without a control (a verb that cannot apply) is kept in a hidden input. rbac.js dims and
 * locks what "Everything" or an "All" already grants; without it every box stays usable.
 * Delegation: what the signed-in user doesn't cover (PermissionCoverage) is rendered disabled
 * ("You can't grant this"); RoleRules refuses a save that adds it anyway.
 * Its words are rbac's (Messages: the admin's language); type, section and permission labels
 * come from Folio's DisplayLabels, so they read as everywhere else in the admin.
 */
final class PermissionsFieldType implements FieldType
{
    private const VERBS = ['view' => 'View', 'create' => 'Create', 'edit' => 'Edit', 'publish' => 'Publish', 'delete' => 'Delete'];

    private readonly Messages $messages;

    public function __construct(
        private readonly PermissionRegistry $registry,
        private readonly TypeCatalog $catalog,
        private readonly ?PermissionCoverage $coverage = null,
        ?Messages $messages = null,
        private readonly ?DisplayLabels $labels = null,
    ) {
        $this->messages = $messages ?? Messages::english();
    }

    public function key(): string
    {
        return 'rbac_permissions';
    }

    public function renderEditor(FieldContext $ctx): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = fn (string $key, array $params = []): string => $this->messages->get('matrix.' . $key, $params);
        $deniedText = $t('denied');
        $verbs = [];
        foreach (self::VERBS as $action => $verb) {
            $verbs[$action] = $this->labels?->permission('*.' . $action, $verb) ?? $verb;
        }
        $selected = RoleList::normalize($ctx->value);
        $name = $e($ctx->name . '[]');
        $held = $this->coverage?->held();
        $denied = static fn (string $value): string => $held !== null && !PermissionCoverage::covers($held, $value)
            ? ' disabled data-rbac-denied title="' . $e($deniedText) . '"' : '';
        $input = static fn (string $value, ?string $title = null, string $attrs = '') => '<input type="checkbox" name="' . $name . '" value="' . $e($value) . '"'
            . ($title !== null && $denied($value) === '' ? ' title="' . $e($title) . '"' : '') . $attrs . $denied($value)
            . (in_array($value, $selected, true) ? ' checked' : '') . '>';
        [$sections, $others] = $this->layout();

        $html = '<fieldset class="form-group rbac-permissions' . ($ctx->errors !== [] ? ' has-error' : '') . '" data-rbac-permissions>'
            . '<legend class="rbac-legend">' . $e($ctx->label ?? 'Permissions') . '</legend>'
            . '<input type="hidden" name="' . $name . '" value="">' // none checked still posts an empty list
            . '<label class="rbac-everything"><span class="rbac-everything-text"><span class="rbac-everything-label">' . $e($t('everything')) . '</span>'
            . '<span class="rbac-everything-hint">' . str_replace(':pattern', '<code>*</code>', $e($t('everything_hint', ['pattern' => ':pattern']))) . '</span></span>'
            . '<input type="checkbox" class="rbac-switch-input" role="switch" name="' . $name . '" value="*" data-rbac-everything' . $denied('*') . (in_array('*', $selected, true) ? ' checked' : '') . '>'
            . '<span class="rbac-switch" aria-hidden="true"></span></label>';
        $unknown = array_diff($selected, $this->known());
        if ($unknown !== []) {
            $html .= '<p class="rbac-unknown">' . str_replace(':names', implode(', ', array_map(static fn (string $p): string => '<code>' . $e($p) . '</code>', $unknown)), $e($t('unknown', ['names' => ':names']))) . '</p>';
        }

        $html .= '<div class="rbac-rest" data-rbac-rest>';
        // Stored, declared names without a control here (a verb that cannot apply): kept as they are.
        foreach (array_diff(array_intersect($selected, $this->known()), $this->controlled($sections, $others)) as $kept) {
            $html .= '<input type="hidden" name="' . $name . '" value="' . $e($kept) . '" data-rbac-kept>';
        }
        if ($sections !== []) {
            $html .= '<div class="folio-table-wrap"><table class="folio-table rbac-matrix"><thead><tr><th scope="col">' . $e($t('type')) . '</th>';
            foreach ([...$verbs, $t('all')] as $verb) {
                $html .= '<th scope="col">' . $e($verb) . '</th>';
            }
            $html .= '</tr></thead><tbody>';
            foreach ($sections as $section => $rows) {
                $html .= "\n" . '<tr class="rbac-section"><th colspan="' . (count(self::VERBS) + 2) . '" scope="colgroup">' . $e($section) . '</th></tr>';
                foreach ($rows as $row) {
                    $label = $e($row['label']);
                    $html .= "\n" . '<tr class="rbac-row" data-rbac-row><th scope="row" title="' . $e($row['key']) . '.*">' . $label . '</th>';
                    foreach ($verbs as $action => $verb) {
                        $permission = $row['key'] . '.' . $action;
                        $html .= '<td class="rbac-cell">' . (in_array($permission, $row['names'], true)
                            ? $input($permission, $permission, ' aria-label="' . $label . ': ' . $e($verb) . '"')
                            : '<span class="rbac-none" title="' . $e($t('none', ['verb' => $verb === self::VERBS[$action] ? $action : $verb, 'type' => $row['label']])) . '">–</span>') . '</td>';
                    }
                    $html .= '<td class="rbac-cell rbac-cell-all">' . ($row['pattern'] !== null
                        ? $input($row['pattern'], $row['pattern'], ' aria-label="' . $label . ': ' . $e($t('all')) . '" data-rbac-all')
                        : '<span class="rbac-none">–</span>') . '</td></tr>';
                }
            }
            $html .= '</tbody></table></div>';
        }
        if ($others !== []) {
            $html .= "\n" . '<div class="rbac-others"><h3 class="rbac-others-heading">' . $e($t('other')) . '</h3>';
            foreach ($others as $group => $entries) {
                $first = (string) (array_key_first($entries['names']) ?? $entries['patterns'][0] ?? '');
                $heading = $group === 'Other' ? $t('other_group') : ($this->labels?->permissionGroup((string) $group, $first) ?? (string) $group);
                $html .= "\n" . '<div class="rbac-group" data-rbac-group><div class="rbac-group-head"><span class="rbac-group-label">' . $e($heading) . '</span>';
                $patterns = $entries['patterns'];
                $all = array_shift($patterns);
                if ($all !== null) {
                    $html .= '<label class="rbac-group-all" title="' . $e($all) . '">' . $input($all, null, ' data-rbac-all') . ' ' . $e($t('all')) . '</label>';
                }
                $html .= '</div><div class="rbac-chips">';
                $chips = [...array_fill_keys($patterns, ''), ...$entries['names']]; // further patterns of the group: chips named as the pattern
                foreach ($chips as $permission => $chipLabel) {
                    $permission = (string) $permission;
                    $chipText = $chipLabel !== '' ? ($this->labels?->permission($permission, $chipLabel) ?? $chipLabel) : $permission;
                    $html .= '<label class="rbac-chip" title="' . $e($permission) . '">' . $input($permission)
                        . '<span>' . $e($chipText) . '</span></label>';
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
     * verbs that can apply, i.e. no create or delete on a singleton and no publish without
     * publishing, and "{type}.*" if declared) by sidebar section label, in sidebar order; and every
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
            $taken += array_fill_keys($names, true); // declared, so never a chip; only shown where they can apply
            $inapplicable = [...($listing->singleton ? ['create', 'delete'] : []), ...($listing->publishing ? [] : ['publish'])];
            $names = array_values(array_filter($names, static fn (string $permission): bool => !in_array(substr($permission, strlen($listing->key) + 1), $inapplicable, true)));
            $pattern = in_array($listing->key . '.*', $patterns, true) ? $listing->key . '.*' : null;
            $bySection[$listing->section][] = ['key' => $listing->key, 'label' => $this->labels?->type($listing->key) ?? $listing->label, 'names' => $names, 'pattern' => $pattern];
            if ($pattern !== null) {
                $taken[$pattern] = true;
            }
        }
        $sections = [];
        foreach (AdminNav::sectionOrder(array_keys($bySection)) as $section) {
            if (isset($bySection[$section])) {
                $builtin = in_array($section, AdminNav::sectionOrder([]), true);
                $sections[$builtin || $this->labels === null ? AdminNav::sectionLabel($section) : $this->labels->section($section)] = $bySection[$section];
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

    /**
     * Every value the editor has a control for: "*", the rows' verbs and patterns, the chips and group patterns.
     *
     * @param array<string, list<array{names: list<string>, pattern: ?string}>> $sections
     * @param array<string, array{patterns: list<string>, names: array<string, string>}> $others
     * @return list<string>
     */
    private function controlled(array $sections, array $others): array
    {
        $values = ['*'];
        foreach ($sections as $rows) {
            foreach ($rows as $row) {
                array_push($values, ...$row['names'], ...($row['pattern'] !== null ? [$row['pattern']] : []));
            }
        }
        foreach ($others as $group) {
            array_push($values, ...$group['patterns'], ...array_map('strval', array_keys($group['names'])));
        }

        return $values;
    }

    /** @return list<string> */
    private function known(): array
    {
        return ['*', ...array_map('strval', array_keys($this->registry->all())), ...$this->registry->patterns()];
    }
}
