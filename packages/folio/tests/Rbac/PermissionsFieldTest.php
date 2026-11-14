<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Rbac;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Access\PermissionRegistry;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Rbac\Fields\PermissionsFieldType;

/**
 * The permissions editor: "Everything" as a switch, the content types as a matrix (rows by
 * sidebar section, a column per standard verb, "All" = "{type}.*"), every other permission
 * as a chip under its group, unknown stored names as one warning line.
 */
final class PermissionsFieldTest extends TestCase
{
    use RbacAppScaffold;

    private const VERBS = ['view' => 'View', 'create' => 'Create', 'edit' => 'Edit', 'publish' => 'Publish', 'delete' => 'Delete'];

    /** As Folio and extensions declare them: types (members' "member" in its own section, no publish on "post"), Folio's own, assets, codes. */
    /** @param list<string>|null $fresh what an update brought (null: no source) */
    private function field(?array $fresh = null): PermissionsFieldType
    {
        $catalog = new TypeCatalog('/no/such/models');
        $catalog->add(['key' => 'rbac_role', 'label' => 'Roles', 'fields' => []], 'settings');
        $catalog->add(['key' => 'member', 'label' => 'Members', 'section' => 'members', 'fields' => []]);
        $catalog->add(['key' => 'post', 'label' => 'Posts', 'fields' => []]);
        $catalog->add(['key' => 'page', 'label' => 'Pages', 'section' => 'pages', 'publishing' => true, 'fields' => []]);
        $catalog->add(['key' => 'site', 'label' => 'Site', 'singleton' => true, 'fields' => []], 'settings');
        $catalog->add(['key' => 'members_settings', 'label' => 'Members settings', 'singleton' => true, 'publishing' => false, 'fields' => []], 'settings');

        $registry = new PermissionRegistry();
        $registry->declare('folio.dashboard', 'Dashboard', 'Folio');
        $registry->declare('folio.extensions', 'Extensions', 'Folio');
        $registry->declare('folio.cache', 'Cache', 'Folio');
        foreach (['rbac_role' => 'Roles', 'member' => 'Members', 'post' => 'Posts', 'page' => 'Pages', 'site' => 'Site', 'members_settings' => 'Members'] as $type => $label) {
            $registry->declarePattern($type . '.*');
            foreach (self::VERBS as $action => $verb) {
                if ($type !== 'post' || $action !== 'publish') {
                    $registry->declare($type . '.' . $action, $verb, $label);
                }
            }
        }
        $registry->declarePattern('assets.*');
        foreach (['view' => 'View', 'upload' => 'Upload', 'edit' => 'Edit', 'delete' => 'Delete'] as $action => $verb) {
            $registry->declare('assets.' . $action, $verb, 'Assets');
        }
        $registry->declare('codes.revoke', 'Revoke codes', 'Codes');
        $registry->declare('members.access', 'Use the members area', 'Members');

        return new PermissionsFieldType($registry, $catalog, fresh: $fresh === null ? null : static fn (): array => $fresh);
    }

    /**
     * @param list<string> $value
     * @param list<string>|null $fresh
     */
    private function render(array $value, ?array $fresh = null): string
    {
        return $this->field($fresh)->renderEditor(new FieldContext(name: 'permissions', label: 'Permissions', value: $value));
    }

    /** The row of a type: its header cell's inner HTML and its cells. */
    private static function row(string $html, string $type): string
    {
        self::assertSame(1, preg_match('#<tr class="rbac-row[^"]*" data-rbac-row[^>]*><th scope="row" title="' . preg_quote($type, '#') . '\\.\\*">.*?</tr>#', $html, $m), $type);

        return $m[0];
    }

    public function test_permissions_matrix_rows_and_columns(): void
    {
        $html = $this->render(['page.*', 'post.view']);

        $this->assertMatchesRegularExpression('#<div class="folio-table-wrap"><table class="folio-table rbac-matrix">\s*<tbody class="rbac-section-body" data-rbac-section>#', $html);
        // A block per sidebar section, in sidebar order: Pages, Content, the custom Members, Settings. Its header
        // row names the section with how many of its verbs are granted ("page.*" grants Pages' five), then a
        // button per verb that toggles that column within the section, then "All".
        preg_match_all('#<tbody class="rbac-section-body" data-rbac-section><tr class="rbac-section"><th scope="colgroup">([^<]+) <span class="rbac-section-sum">· <span data-rbac-sum>(\d+)</span> granted</span></th>#', $html, $m);
        $this->assertSame(['Pages', 'Content', 'Members', 'Settings'], $m[1]);
        $this->assertSame(['5', '1', '0', '0'], $m[2]);
        $this->assertMatchesRegularExpression(
            '#<th scope="colgroup">Content <span class="rbac-section-sum">[^<]*<span data-rbac-sum>1</span> granted</span></th>'
            . '<th scope="col"><button type="button" class="rbac-column" data-rbac-column="view" title="Toggle “View” for every type in Content" aria-label="Toggle “View” for every type in Content">View</button></th>'
            . '<th scope="col"><button type="button" class="rbac-column" data-rbac-column="create"[^>]*>Create</button></th>'
            . '<th scope="col"><button type="button" class="rbac-column" data-rbac-column="edit"[^>]*>Edit</button></th>'
            . '<th scope="col"><button type="button" class="rbac-column" data-rbac-column="publish"[^>]*>Publish</button></th>'
            . '<th scope="col"><button type="button" class="rbac-column" data-rbac-column="delete"[^>]*>Delete</button></th>'
            . '<th scope="col" class="rbac-cell-all">All</th></tr>#',
            $html,
        );
        $this->assertMatchesRegularExpression('#scope="colgroup">Members <span[^>]*>.*?</th></tr>\s*<tr class="rbac-row" data-rbac-row><th scope="row" title="member\.\*"><span class="rbac-type"><span class="folio-type-icon"[^>]*>.*?</span><span class="rbac-type-label">Members</span></span></th>#', $html);
        $this->assertMatchesRegularExpression('#scope="colgroup">Settings <span[^>]*>.*?</th></tr>\s*<tr class="rbac-row" data-rbac-row><th scope="row" title="rbac_role\.\*">.*?<span class="rbac-type-label">Roles</span>#', $html);

        // A row: its type tile and label, a box per verb, the row's switch is "{type}.*" (checked here).
        $this->assertMatchesRegularExpression(
            '#<tr class="rbac-row" data-rbac-row><th scope="row" title="page\.\*"><span class="rbac-type"><span class="folio-type-icon" data-family="" aria-hidden="true"><svg class="folio-icon"[^>]*>.*?</svg></span><span class="rbac-type-label">Pages</span></span></th>'
            . '<td class="rbac-cell"><input type="checkbox" name="permissions\[\]" value="page\.view" title="page\.view" class="rbac-box" data-rbac-verb="view" aria-label="Pages: View"></td>'
            . '(<td class="rbac-cell"><input type="checkbox" name="permissions\[\]" value="page\.(create|edit|publish|delete)" title="[^"]+" class="rbac-box" data-rbac-verb="\2" aria-label="Pages: [A-Za-z]+"></td>){4}'
            . '<td class="rbac-cell rbac-cell-all"><input type="checkbox" name="permissions\[\]" value="page\.\*" title="page\.\*" class="folio-switch rbac-row-switch" role="switch" aria-label="Pages: All" data-rbac-all checked></td></tr>#',
            $html,
        );
        $this->assertMatchesRegularExpression('#value="post\.view" title="post\.view" class="rbac-box" data-rbac-verb="view" aria-label="Posts: View" checked>#', $html);
        // A verb the type does not declare: a muted dash, no checkbox.
        $this->assertMatchesRegularExpression('#aria-label="Posts: Edit"></td><td class="rbac-cell"><span class="rbac-none" title="No publish permission for Posts">–</span></td><td class="rbac-cell"><input[^>]+value="post\.delete"#', $html);
        $this->assertStringNotContainsString('value="post.publish"', $html);
        // Type verbs never show up again among the other permissions.
        $this->assertSame(1, substr_count($html, 'value="member.view"'));
        // The key above the matrix: granted and n/a only (the engine checks exact names: nothing is implied).
        $this->assertMatchesRegularExpression('#<div class="rbac-bar"><ul class="rbac-key" aria-label="Legend"><li><span class="rbac-key-box" aria-hidden="true"></span>Granted</li><li><span class="rbac-none" aria-hidden="true">–</span>Not available for this type</li></ul></div>#', $html);
        $this->assertMatchesRegularExpression('#</table></div><p class="rbac-footnote">Column headers toggle the whole column\.</p>#', $html);
        $this->assertStringNotContainsString('implie', $html);
    }

    public function test_everything_counts_every_verb_and_the_type_tile_follows_the_family(): void
    {
        $catalog = new TypeCatalog('/no/such/models');
        $catalog->add(['key' => 'job', 'label' => 'Jobs', 'family' => 'reference', 'icon' => 'ui-users', 'fields' => []]);
        $catalog->add(['key' => 'odd', 'label' => 'Odd', 'icon' => 'ui-no-such-glyph', 'fields' => []]);
        $registry = new PermissionRegistry();
        foreach (['job', 'odd'] as $type) {
            $registry->declare($type . '.view', 'View', $type);
            $registry->declare($type . '.edit', 'Edit', $type);
        }
        $html = (new PermissionsFieldType($registry, $catalog))->renderEditor(new FieldContext(name: 'permissions', label: 'Permissions', value: ['*']));

        $this->assertMatchesRegularExpression('#<span data-rbac-sum>4</span> granted#', $html);
        $this->assertMatchesRegularExpression('#title="job\.\*"><span class="rbac-type"><span class="folio-type-icon" data-family="reference" aria-hidden="true"><svg class="folio-icon"#', $html);
        // A glyph the sprite lacks: the file icon, not an error.
        $this->assertMatchesRegularExpression('#title="odd\.\*"><span class="rbac-type"><span class="folio-type-icon" data-family="" aria-hidden="true"><svg#', $html);
        // No pattern declared: no row switch, a dash.
        $this->assertMatchesRegularExpression('#<td class="rbac-cell rbac-cell-all"><span class="rbac-none">–</span></td></tr>#', $html);
    }

    public function test_fresh_permissions_are_marked_new_with_the_only_new_filter(): void
    {
        // job's view, a chip and a group pattern came with an update; a fresh name without a control here is not counted.
        $html = $this->render(['page.view'], ['page.view', 'codes.revoke', 'assets.*', 'ghost.view']);

        $this->assertMatchesRegularExpression('#<button type="button" class="rbac-fresh-filter" aria-pressed="false" data-rbac-fresh-filter data-folio-no-edit>Only new \(3\)</button></div>#', $html);
        $this->assertMatchesRegularExpression('#<tr class="rbac-row is-new" data-rbac-row data-rbac-new><th scope="row" title="page\.\*"><span class="rbac-type">.*?<span class="rbac-type-label">Pages</span><span class="folio-badge is-warning rbac-fresh-badge">New</span></span></th>#', $html);
        $this->assertSame(1, preg_match_all('#<tr class="rbac-row is-new"#', $html));
        $this->assertMatchesRegularExpression('#<label class="rbac-chip is-new" title="codes\.revoke" data-rbac-new><input type="checkbox" name="permissions\[\]" value="codes\.revoke"><span>Revoke codes</span><span class="folio-badge is-warning rbac-fresh-badge">New</span></label>#', $html);
        $this->assertMatchesRegularExpression('#title="assets\.\*"><input[^>]+value="assets\.\*" data-rbac-all> All</label><span class="rbac-fresh-mark" data-rbac-new><span class="folio-badge is-warning rbac-fresh-badge">New</span></span>#', $html);

        // Nothing fresh, or no source: no marks, no filter.
        foreach ([$this->render(['page.view'], []), $this->render(['page.view'])] as $plain) {
            $this->assertStringNotContainsString('data-rbac-new', $plain);
            $this->assertStringNotContainsString('rbac-fresh-filter', $plain);
        }
    }

    public function test_hidden_rows_still_submit(): void
    {
        // "Only new" hides rows in the browser (rbac.js, hidden): the server renders every control enabled
        // and visible, so a hidden row's values post. The JS side: tests/js/rbac-matrix.test.mjs.
        $html = $this->render(['page.view', 'post.*', 'codes.revoke'], ['member.view']);

        $this->assertStringContainsString('data-rbac-fresh-filter', $html);
        preg_match_all('#<input type="checkbox" name="permissions\[\]" value="([^"]+)"[^>]*>#', $html, $m);
        $this->assertContains('page.view', $m[1]);
        $this->assertContains('post.*', $m[1]);
        $this->assertContains('codes.revoke', $m[1]);
        foreach ($m[0] as $tag) {
            $this->assertStringNotContainsString(' disabled', $tag, 'locks are the script\'s, and only for what a pattern covers');
            $this->assertStringNotContainsString(' hidden', $tag);
        }
        $this->assertDoesNotMatchRegularExpression('#<(tr|tbody|div|label)[^>]* hidden[ >]#', preg_replace('#<div class="rbac-changes" data-rbac-changes hidden>#', '', $html));
    }

    public function test_inapplicable_verbs_dashed(): void
    {
        $html = $this->render([]);
        $cells = function (string $type) use ($html): array {
            $this->assertSame(1, preg_match('#<tr class="rbac-row" data-rbac-row><th scope="row" title="' . preg_quote($type, '#') . '\.\*">.*?</th>(.*?)</tr>#', $html, $m));
            preg_match_all('#<td class="rbac-cell[^"]*">(<input[^>]+value="' . preg_quote($type, '#') . '\.([a-z*]+)"|<span class="rbac-none")#', $m[1], $c);

            return array_map(static fn (string $verb): string => $verb === '' ? '–' : $verb, $c[2]);
        };

        // A singleton has no create or delete; a type without publishing has no publish.
        $this->assertSame(['view', '–', 'edit', 'publish', '–', '*'], $cells('site'));
        $this->assertSame(['view', '–', 'edit', '–', '–', '*'], $cells('members_settings'));
        $this->assertSame(['view', 'create', 'edit', '–', 'delete', '*'], $cells('member'));
        $this->assertSame(['view', 'create', 'edit', 'publish', 'delete', '*'], $cells('page'));
        $this->assertMatchesRegularExpression('#<span class="rbac-none" title="No create permission for Site">–</span>#', $html);
        // Hidden only in the editor: the names stay declared, so they are never offered elsewhere either.
        $this->assertStringNotContainsString('value="site.create"', $html);
        $this->assertStringNotContainsString('value="member.publish"', $html);
    }

    public function test_other_permissions_as_chips(): void
    {
        $html = $this->render(['codes.revoke', 'assets.*']);

        $this->assertMatchesRegularExpression('#<div class="rbac-others" data-rbac-others><h3 class="rbac-others-heading">Other permissions</h3>#', $html);
        preg_match_all('#<div class="rbac-group" data-rbac-group><div class="rbac-group-head"><span class="rbac-group-label">([^<]+)</span>#', $html, $m);
        $this->assertSame(['Folio', 'Assets', 'Codes', 'Members'], $m[1]);
        $this->assertMatchesRegularExpression(
            '#<span class="rbac-group-label">Folio</span></div><div class="rbac-chips">'
            . '<label class="rbac-chip" title="folio\.dashboard"><input type="checkbox" name="permissions\[\]" value="folio\.dashboard"><span>Dashboard</span></label>'
            . '<label class="rbac-chip" title="folio\.extensions"><input type="checkbox" name="permissions\[\]" value="folio\.extensions"><span>Extensions</span></label>'
            . '<label class="rbac-chip" title="folio\.cache"><input type="checkbox" name="permissions\[\]" value="folio\.cache"><span>Cache</span></label></div></div>#',
            $html,
        );
        // A declared group pattern is the group header's own "All" checkbox.
        $this->assertMatchesRegularExpression(
            '#<span class="rbac-group-label">Assets</span><label class="rbac-group-all" title="assets\.\*"><input type="checkbox" name="permissions\[\]" value="assets\.\*" data-rbac-all checked> All</label></div><div class="rbac-chips"><label class="rbac-chip" title="assets\.view">#',
            $html,
        );
        $this->assertMatchesRegularExpression('#<span class="rbac-group-label">Codes</span></div><div class="rbac-chips"><label class="rbac-chip" title="codes\.revoke"><input type="checkbox" name="permissions\[\]" value="codes\.revoke" checked><span>Revoke codes</span></label></div>#', $html);
        $this->assertMatchesRegularExpression('#<span class="rbac-group-label">Members</span></div><div class="rbac-chips"><label class="rbac-chip" title="members\.access"><input[^>]+value="members\.access"><span>Use the members area</span></label></div>#', $html);
        $this->assertStringNotContainsString('All of', $html);
    }

    public function test_everything_switch_renders_and_unknown_names_warn(): void
    {
        $html = $this->render(['*', 'ghost.view']);

        $this->assertMatchesRegularExpression('#^<fieldset class="form-group rbac-permissions" data-rbac-permissions><legend class="rbac-legend">Permissions</legend><input type="hidden" name="permissions\[\]" value="">'
            // The unsaved count and Cancel (rbac.js shows them next to Save), then the switch.
            . '<div class="rbac-changes" data-rbac-changes hidden><span class="rbac-changes-count" role="status" data-rbac-changes-count data-text=":count unsaved change\|:count unsaved changes"></span><button type="button" class="btn btn-ghost" data-rbac-cancel>Cancel</button></div><label class="rbac-everything">#', $html);
        $this->assertMatchesRegularExpression(
            '#<label class="rbac-everything"><span class="rbac-everything-text"><span class="rbac-everything-label">Allow everything</span>'
            . '<span class="rbac-everything-hint">Every permission, including those of future extensions\. Admins only\.</span></span>'
            . '<input type="checkbox" class="rbac-switch-input" role="switch" name="permissions\[\]" value="\*" data-rbac-everything checked><span class="rbac-switch" aria-hidden="true"></span></label>#',
            $html,
        );
        // Unknown stored names: one muted warning line above the matrix.
        $this->assertMatchesRegularExpression('#<p class="rbac-unknown">Unknown, removed on save: <code>ghost\.view</code></p><div class="rbac-rest" data-rbac-rest><div class="rbac-bar">.*?</div><div class="folio-table-wrap">#', $html);
        $this->assertStringNotContainsString('value="ghost.view"', $html);
        $this->assertStringNotContainsString(' style=', $html);
        $this->assertDoesNotMatchRegularExpression('#<[^>]+\son[a-z]+=#', $html);
        $this->assertSame(['rbac.css', 'rbac.js'], $this->field()->assets());
    }

    #[RunInSeparateProcess]
    public function test_everything_switch_renders_and_saves_star(): void
    {
        $this->scaffold();
        try {
            $this->install();
            $this->role('r-editor', 'staff', 'editor', ['page.view']);
            $this->as('admin');

            $form = (string) $this->send('GET', '/folio/rbac_role/r-editor/edit')->getBody();
            $this->assertMatchesRegularExpression('#<input type="checkbox" class="rbac-switch-input" role="switch" name="permissions\[\]" value="\*" data-rbac-everything><span class="rbac-switch" aria-hidden="true"></span>#', $form);
            // Loaded only where the field renders, from Folio's asset route.
            $this->assertMatchesRegularExpression('#<link rel="stylesheet" href="/folio/_assets/rbac\.css\?v=[0-9a-f]{12}">#', $form);
            $this->assertMatchesRegularExpression('#<script src="/folio/_assets/rbac\.js\?v=[0-9a-f]{12}" defer></script>#', $form);
            $this->assertStringNotContainsString('rbac.css', (string) $this->send('GET', '/folio/page')->getBody());
            $css = $this->send('GET', '/folio/_assets/rbac.css');
            $this->assertSame(200, $css->getStatusCode());
            $this->assertStringContainsString('var(--c-accent-soft)', (string) $css->getBody());
            $this->assertSame(200, $this->send('GET', '/folio/_assets/rbac.js')->getStatusCode());

            $this->assertSame(302, $this->send('POST', '/folio/rbac_role/r-editor', ['name' => 'editor', 'label' => 'Editor', 'population' => 'staff', 'permissions' => ['', '*']])->getStatusCode());
            $this->assertSame(['*'], $this->stored('rbac_role', 'r-editor')?->get('permissions'));
            $this->assertMatchesRegularExpression('#value="\*" data-rbac-everything checked>#', (string) $this->send('GET', '/folio/rbac_role/r-editor/edit')->getBody());
            $this->assertSame([], $this->errorLog());
        } finally {
            $this->removeScaffold();
        }
    }

    #[RunInSeparateProcess]
    public function test_stored_names_without_control_survive_save(): void
    {
        $this->scaffold();
        try {
            $this->install();
            // No publishing on page and rbac_role: their publish has no checkbox, but stays declared.
            $stored = ['page.publish', 'page.view', 'rbac_role.publish', 'ghost.view'];
            $this->role('r-editor', 'staff', 'editor', $stored);
            $this->as('admin');

            $form = (string) $this->send('GET', '/folio/rbac_role/r-editor/edit')->getBody();
            $this->assertMatchesRegularExpression('#<input type="hidden" name="permissions\[\]" value="page\.publish" data-rbac-kept>#', $form);
            $this->assertMatchesRegularExpression('#<input type="hidden" name="permissions\[\]" value="rbac_role\.publish" data-rbac-kept>#', $form);
            $this->assertStringNotContainsString('<code>page.publish</code>', $form); // not "unknown"
            $this->assertStringContainsString('Unknown, removed on save: <code>ghost.view</code>', $form);

            // What a browser posts: the empty marker, the checked boxes and the kept names.
            preg_match_all('#<input type="(?:checkbox|hidden)" name="permissions\[\]" value="([^"]*)"(?:[^>]* checked| data-rbac-kept|)>#', $form, $m);
            $posted = array_values(array_filter($m[0] === [] ? [] : array_map(
                static fn (string $tag, string $value): ?string => str_contains($tag, ' checked') || str_contains($tag, 'data-rbac-kept') || $value === '' ? $value : null,
                $m[0],
                $m[1],
            ), static fn (?string $v): bool => $v !== null));
            $this->assertEqualsCanonicalizing(['', 'page.publish', 'page.view', 'rbac_role.publish'], $posted);
            $this->assertSame(302, $this->send('POST', '/folio/rbac_role/r-editor', ['name' => 'editor', 'label' => 'Editor', 'population' => 'staff', 'permissions' => $posted])->getStatusCode());
            $this->assertEqualsCanonicalizing(['page.publish', 'page.view', 'rbac_role.publish'], $this->stored('rbac_role', 'r-editor')?->get('permissions'));
        } finally {
            $this->removeScaffold();
        }
    }

    #[RunInSeparateProcess]
    public function test_saved_values_roundtrip_unchanged(): void
    {
        $this->scaffold();
        try {
            $this->install();
            $this->role('r-editor', 'staff', 'editor', []);
            $this->as('admin');
            $this->container()->get(\Preflow\Auth\Access\Access::class)->registry()->declare('codes.revoke', 'Revoke codes', 'Codes');

            // Verbs, a row's All, a group's All, others, an undeclared name (dropped), order kept.
            $posted = ['', 'member.view', 'member.edit', 'page.*', 'assets.*', 'assets.view', 'folio.dashboard', 'codes.revoke', 'ghost.view', 'rbac_role.view'];
            $this->assertSame(302, $this->send('POST', '/folio/rbac_role/r-editor', ['name' => 'editor', 'label' => 'Editor', 'population' => 'staff', 'permissions' => $posted])->getStatusCode());
            $stored = ['member.view', 'member.edit', 'page.*', 'assets.*', 'assets.view', 'folio.dashboard', 'codes.revoke', 'rbac_role.view'];
            $this->assertSame($stored, $this->stored('rbac_role', 'r-editor')?->get('permissions'));

            // The form shows exactly these checked, and saving them again changes nothing.
            $form = (string) $this->send('GET', '/folio/rbac_role/r-editor/edit')->getBody();
            preg_match_all('#name="permissions\[\]" value="([^"]+)"[^>]* checked>#', $form, $m);
            $this->assertEqualsCanonicalizing($stored, $m[1]);
            $this->assertSame(302, $this->send('POST', '/folio/rbac_role/r-editor', ['name' => 'editor', 'label' => 'Editor', 'population' => 'staff', 'permissions' => ['', ...$m[1]]])->getStatusCode());
            $this->assertEqualsCanonicalizing($stored, $this->stored('rbac_role', 'r-editor')?->get('permissions'));

            // None checked: the hidden input posts an empty list.
            $this->send('POST', '/folio/rbac_role/r-editor', ['name' => 'editor', 'label' => 'Editor', 'population' => 'staff', 'permissions' => ['']]);
            $this->assertSame([], $this->stored('rbac_role', 'r-editor')?->get('permissions'));
            $this->assertSame([], $this->errorLog());
        } finally {
            $this->removeScaffold();
        }
    }
}
