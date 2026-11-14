<?php

declare(strict_types=1);

namespace Preflow\Folio\Rbac\Fields;

use Preflow\Auth\Access\PermissionRegistry;
use Preflow\Folio\Content\DisplayLabels;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldType;
use Preflow\Folio\FolioText;
use Preflow\Folio\Http\AdminNav;
use Preflow\Folio\View\InlineIcon;
use Preflow\Rbac\PermissionCoverage;
use Preflow\Rbac\RoleList;

/**
 * A role's permissions ("rbac_permissions"), in Folio's look (board Roles): "Allow everything" (*)
 * as a switch on top; the content types as a matrix, one block per sidebar section (its header row
 * names the section with how many of its verbs are granted, and a button per standard verb that
 * toggles that column within the section), a type tile per row, a box per verb that applies ("–"
 * where it can't), and a switch per row for "{type}.*"; then every other declared permission as a
 * chip under its group (the group's pattern, if declared, as the group's "All"). The value is a list
 * of names and patterns; anything not declared is shown as unknown and dropped on save, a stored
 * declared name without a control (a verb that cannot apply) is kept in a hidden input.
 *
 * The engine checks exact names (no permission implies another), so the matrix shows granted and
 * n/a only. Permissions an update brought (NewPermissions::forManager(): only for a user who may
 * edit roles) carry a "New" badge, with an "Only new (N)" filter that hides the other rows (their
 * values still post). rbac.js locks what "Everything" or a row's switch already grants, toggles
 * columns, counts the unsaved changes and puts them with Cancel next to the editor's Save; without
 * it every box stays usable. Delegation: what the signed-in user doesn't cover (PermissionCoverage)
 * is rendered disabled ("You can't grant this"); RoleRules refuses a save that adds it anyway, and
 * no column or row switch ever changes such a box.
 * Its words are the "rbac-admin" group's (FolioText: the admin's language); type, section and permission labels
 * come from Folio's DisplayLabels, so they read as everywhere else in the admin.
 */
final class PermissionsFieldType implements FieldType
{
    private const VERBS = ['view' => 'View', 'create' => 'Create', 'edit' => 'Edit', 'publish' => 'Publish', 'delete' => 'Delete'];

    /**
     * @param (\Closure(): list<string>)|null $fresh the permissions an update brought, for the signed-in
     *        user (NewPermissions::forManager(): [] for anyone who may not edit roles); @since 0.76
     */
    public function __construct(
        private readonly PermissionRegistry $registry,
        private readonly TypeCatalog $catalog,
        private readonly ?PermissionCoverage $coverage = null,
        private readonly ?DisplayLabels $labels = null,
        private readonly ?\Closure $fresh = null,
    ) {}

    public function key(): string
    {
        return 'rbac_permissions';
    }

    public function renderEditor(FieldContext $ctx): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = fn (string $key, array $params = []): string => FolioText::get('rbac-admin.matrix.' . $key, $params);
        $deniedText = $t('denied');
        $verbs = [];
        foreach (self::VERBS as $action => $verb) {
            $verbs[$action] = $this->labels?->permission('*.' . $action, $verb) ?? $verb;
        }
        $selected = RoleList::normalize($ctx->value);
        $everything = in_array('*', $selected, true);
        $name = $e($ctx->name . '[]');
        $held = $this->coverage?->held();
        $denied = static fn (string $value): string => $held !== null && !PermissionCoverage::covers($held, $value)
            ? ' disabled data-rbac-denied title="' . $e($deniedText) . '"' : '';
        $input = static fn (string $value, ?string $title = null, string $attrs = '') => '<input type="checkbox" name="' . $name . '" value="' . $e($value) . '"'
            . ($title !== null && $denied($value) === '' ? ' title="' . $e($title) . '"' : '') . $attrs . $denied($value)
            . (in_array($value, $selected, true) ? ' checked' : '') . '>';
        [$sections, $others] = $this->layout();
        $fresh = array_flip(array_intersect($this->fresh(), $this->controlled($sections, $others)));
        $newBadge = '<span class="folio-badge is-warning rbac-fresh-badge">' . $e($t('new')) . '</span>';

        $html = '<fieldset class="form-group rbac-permissions' . ($ctx->errors !== [] ? ' has-error' : '') . '" data-rbac-permissions>'
            . '<legend class="rbac-legend">' . $e($ctx->label ?? 'Permissions') . '</legend>'
            . '<input type="hidden" name="' . $name . '" value="">' // none checked still posts an empty list
            // The unsaved count and Cancel: rbac.js shows them next to the editor's Save.
            . '<div class="rbac-changes" data-rbac-changes hidden><span class="rbac-changes-count" role="status" data-rbac-changes-count data-text="' . $e($t('changes')) . '"></span>'
            . '<button type="button" class="btn btn-ghost" data-rbac-cancel>' . $e($t('cancel')) . '</button></div>'
            . '<label class="rbac-everything"><span class="rbac-everything-text"><span class="rbac-everything-label">' . $e($t('everything')) . '</span>'
            . '<span class="rbac-everything-hint">' . $e($t('everything_hint')) . '</span></span>'
            . '<input type="checkbox" class="rbac-switch-input" role="switch" name="' . $name . '" value="*" data-rbac-everything' . $denied('*') . ($everything ? ' checked' : '') . '>'
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
        // The key (granted, "–") and, when an update brought permissions, the "Only new (N)" filter.
        $newCount = 0;
        foreach ($sections as $rows) {
            foreach ($rows as $row) {
                $newCount += $this->isFresh($row, $fresh) ? 1 : 0;
            }
        }
        foreach ($others as $group) {
            foreach ([...$group['patterns'], ...array_map('strval', array_keys($group['names']))] as $permission) {
                $newCount += isset($fresh[$permission]) ? 1 : 0;
            }
        }
        $html .= '<div class="rbac-bar"><ul class="rbac-key" aria-label="' . $e($t('legend')) . '">'
            . '<li><span class="rbac-key-box" aria-hidden="true"></span>' . $e($t('granted')) . '</li>'
            . '<li><span class="rbac-none" aria-hidden="true">–</span>' . $e($t('na')) . '</li></ul>'
            . ($newCount > 0 ? '<button type="button" class="rbac-fresh-filter" aria-pressed="false" data-rbac-fresh-filter data-folio-no-edit>' . $e($t('only_new', ['count' => (string) $newCount])) . '</button>' : '')
            . '</div>';
        if ($sections !== []) {
            $html .= '<div class="folio-table-wrap"><table class="folio-table rbac-matrix">';
            foreach ($sections as $section => $rows) {
                // Granted verbs of the section: a box checked, or covered by its row's pattern or "*".
                $sum = 0;
                foreach ($rows as $row) {
                    $whole = $everything || ($row['pattern'] !== null && in_array($row['pattern'], $selected, true));
                    foreach ($row['names'] as $permission) {
                        $sum += $whole || in_array($permission, $selected, true) ? 1 : 0;
                    }
                }
                $html .= "\n" . '<tbody class="rbac-section-body" data-rbac-section><tr class="rbac-section"><th scope="colgroup">' . $e($section)
                    . ' <span class="rbac-section-sum">· ' . str_replace(':count', '<span data-rbac-sum>' . $sum . '</span>', $e($t('sum', ['count' => ':count']))) . '</span></th>';
                foreach ($verbs as $action => $verb) {
                    $column = $e($t('column', ['verb' => $verb, 'section' => $section]));
                    $html .= '<th scope="col"><button type="button" class="rbac-column" data-rbac-column="' . $action . '" title="' . $column . '" aria-label="' . $column . '">' . $e($verb) . '</button></th>';
                }
                $html .= '<th scope="col" class="rbac-cell-all">' . $e($t('all')) . '</th></tr>';
                foreach ($rows as $row) {
                    $label = $e($row['label']);
                    $isNew = $this->isFresh($row, $fresh);
                    $html .= "\n" . '<tr class="rbac-row' . ($isNew ? ' is-new' : '') . '" data-rbac-row' . ($isNew ? ' data-rbac-new' : '') . '><th scope="row" title="' . $e($row['key']) . '.*">'
                        . '<span class="rbac-type">' . $this->tile($row['family'], $row['glyph']) . '<span class="rbac-type-label">' . $label . '</span>' . ($isNew ? $newBadge : '') . '</span></th>';
                    foreach ($verbs as $action => $verb) {
                        $permission = $row['key'] . '.' . $action;
                        $html .= '<td class="rbac-cell">' . (in_array($permission, $row['names'], true)
                            ? $input($permission, $permission, ' class="rbac-box" data-rbac-verb="' . $action . '" aria-label="' . $label . ': ' . $e($verb) . '"')
                            : '<span class="rbac-none" title="' . $e($t('none', ['verb' => $verb === self::VERBS[$action] ? $action : $verb, 'type' => $row['label']])) . '">–</span>') . '</td>';
                    }
                    $html .= '<td class="rbac-cell rbac-cell-all">' . ($row['pattern'] !== null
                        ? $input($row['pattern'], $row['pattern'], ' class="folio-switch rbac-row-switch" role="switch" aria-label="' . $label . ': ' . $e($t('all')) . '" data-rbac-all')
                        : '<span class="rbac-none">–</span>') . '</td></tr>';
                }
                $html .= '</tbody>';
            }
            $html .= '</table></div><p class="rbac-footnote">' . $e($t('footnote')) . '</p>';
        }
        if ($others !== []) {
            $html .= "\n" . '<div class="rbac-others" data-rbac-others><h3 class="rbac-others-heading">' . $e($t('other')) . '</h3>';
            foreach ($others as $group => $entries) {
                $first = (string) (array_key_first($entries['names']) ?? $entries['patterns'][0] ?? '');
                $heading = $group === 'Other' ? $t('other_group') : ($this->labels?->permissionGroup((string) $group, $first) ?? (string) $group);
                $html .= "\n" . '<div class="rbac-group" data-rbac-group><div class="rbac-group-head"><span class="rbac-group-label">' . $e($heading) . '</span>';
                $patterns = $entries['patterns'];
                $all = array_shift($patterns);
                if ($all !== null) {
                    $html .= '<label class="rbac-group-all" title="' . $e($all) . '">' . $input($all, null, ' data-rbac-all') . ' ' . $e($t('all')) . '</label>'
                        . (isset($fresh[$all]) ? '<span class="rbac-fresh-mark" data-rbac-new>' . $newBadge . '</span>' : '');
                }
                $html .= '</div><div class="rbac-chips">';
                $chips = [...array_fill_keys($patterns, ''), ...$entries['names']]; // further patterns of the group: chips named as the pattern
                foreach ($chips as $permission => $chipLabel) {
                    $permission = (string) $permission;
                    $chipText = $chipLabel !== '' ? ($this->labels?->permission($permission, $chipLabel) ?? $chipLabel) : $permission;
                    $isNew = isset($fresh[$permission]);
                    $html .= '<label class="rbac-chip' . ($isNew ? ' is-new' : '') . '" title="' . $e($permission) . '"' . ($isNew ? ' data-rbac-new' : '') . '>' . $input($permission)
                        . '<span>' . $e($chipText) . '</span>' . ($isNew ? $newBadge : '') . '</label>';
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

    /** A row is new when one of its names or its pattern is. @param array{names: list<string>, pattern: ?string} $row @param array<string, int> $fresh */
    private function isFresh(array $row, array $fresh): bool
    {
        foreach ([...$row['names'], ...($row['pattern'] !== null ? [$row['pattern']] : [])] as $permission) {
            if (isset($fresh[$permission])) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> the fresh permissions for the signed-in user; [] without a source */
    private function fresh(): array
    {
        return $this->fresh !== null ? array_values(array_map('strval', ($this->fresh)())) : [];
    }

    /** The type tile (partials/_type_icon.twig in PHP): the type's glyph on its family's tint; a glyph not in the sprite falls back to the file icon. */
    private function tile(?string $family, string $glyph): string
    {
        try {
            $svg = InlineIcon::svg($glyph);
        } catch (\InvalidArgumentException) {
            $svg = InlineIcon::svg('ui-file');
        }

        return '<span class="folio-type-icon" data-family="' . htmlspecialchars($family ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8') . '" aria-hidden="true">' . $svg . '</span>';
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
     * @return array{0: array<string, list<array{key: string, label: string, names: list<string>, pattern: ?string, family: ?string, glyph: string}>>, 1: array<string, array{patterns: list<string>, names: array<string, string>}>}
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
            $bySection[$listing->section][] = ['key' => $listing->key, 'label' => $this->labels?->type($listing->key) ?? $listing->label, 'names' => $names, 'pattern' => $pattern, 'family' => $listing->family?->value, 'glyph' => $listing->glyph()];
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
