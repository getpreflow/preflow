# Folio redesign inventory (Preflow 0.73, Task 0)

Snapshot of `packages/folio` on 2026-11-13, taken before any redesign change. Nothing was changed
visually. Spec: `.superpowers/folio-redesign/folio-redesign-handoff/` (README, PLAN, COMPONENTS, SCREENS,
INK, icons, tokens.css).

Conventions: paths are relative to `packages/folio`. `tpl/` = `templates/admin/`. `src/` = PHP that renders
markup (field types, controllers, extension templates). `admin.css`, `admin.js` = `assets/`. `+N` = N more
lines after the ones listed. Line numbers are from this commit and drift as soon as the CSS is edited.
Scanned: `assets/*.css|js`, `assets/rbac/*`, `templates/`, `src/`; tests excluded.

## 1. COMPONENTS.md classes that exist, and where they are used

`ai-translate.*`, `image-editor.*`, `preview-editor.*` and `rbac/*` use almost none of the COMPONENTS
classes themselves (they bring their own `folio-ai-*`, `folio-image-editor-*`, `folio-pe-*`, `rbac-*`
classes, section 2). The exceptions are `ai-translate.js` (`form-group`, lines 205, 231),
`ai-translate.css` (`btn` 6, `folio-languages` 3, `form-help` 25) and `rbac.css` (`form-group` 14).
Field types render their HTML in PHP, so field classes are found under `src/Field/Types/*`.

| Component | Class / attribute | Where it is used today |
|---|---|---|
| Buttons | `btn` | admin.css:351,369,370,371+38; admin.js:49,118,119,120+34; ai-translate.css:6; tpl/_list_results.twig:21,42,44,48+11; tpl/forgot.twig:21; tpl/password.twig:10,26; tpl/form.twig:7,8,110,116+8; tpl/account.twig:26,49; tpl/extensions.twig:9; tpl/login.twig:57; tpl/_layout.twig:101; tpl/cache.twig:10,27,41,49+1; tpl/list.twig:7,8,9,25+2; tpl/blocks/field.twig:21; tpl/blocks/card.twig:13,14,15,18; tpl/blocks/unknown.twig:9; tpl/publishing/share.twig:6,7; tpl/redirects/index.twig:11,12,38,41+4; tpl/redirects/form.twig:43,44; tpl/ai/_tabs.twig:2,3,4; tpl/ai/glossary.twig:41,42; tpl/ai/run-progress.twig:26,31,42; tpl/ai/run.twig:6,28,29,46+2; tpl/ai/status.twig:30; tpl/ai/tuning.twig:26; tpl/a:panel.twig:15,36,59,74+3; tpl/a:edit.twig:4,18,27,58+5; tpl/a:index.twig:41,45,49,56+2; tpl/a:bin.twig:4,30,41; tpl/a:panel_page.twig:4; tpl/a:_pick.twig:2; tpl/translations/index.twig:29,52,79,81+4; tpl/partials/ai-translate-dialog.twig:30,64,65,66+2; src/Rbac/templates/new-permissions.twig:26,33; src/Rbac/templates/account.twig:20,25,31,36; src/Field/Types/MatrixFieldType.php:92,96,255,256+1; src/Field/Types/AssetFieldType.php:97,104; src/Members/templates/decision.twig:11,20,21,37 |
| Buttons | `btn-primary` | admin.css:373,374,1472; tpl/_list_results.twig:21; tpl/forgot.twig:21; tpl/password.twig:10,26; tpl/form.twig:137,138,140; tpl/account.twig:26,49; tpl/login.twig:57; tpl/list.twig:9,49; tpl/redirects/index.twig:61; tpl/redirects/form.twig:44; tpl/ai/glossary.twig:42; tpl/ai/run-progress.twig:26,42; tpl/ai/run.twig:29,74; tpl/ai/tuning.twig:26; tpl/a:panel.twig:120; tpl/a:edit.twig:58,94; tpl/a:index.twig:56; tpl/translations/index.twig:52; tpl/partials/ai-translate-dialog.twig:66; src/Members/templates/decision.twig:20 |
| Buttons | `btn-secondary` | admin.css:376,377; admin.js:49; tpl/_list_results.twig:88; tpl/form.twig:7,137; tpl/extensions.twig:9; tpl/cache.twig:10,41,49,61; tpl/list.twig:7,8,25,53; tpl/blocks/field.twig:21; tpl/redirects/index.twig:11,12,38,59; tpl/ai/_tabs.twig:2,3,4; tpl/ai/glossary.twig:41; tpl/ai/run-progress.twig:31; tpl/ai/run.twig:6,46; tpl/ai/status.twig:30; tpl/a:panel.twig:15,59; tpl/a:edit.twig:4,18,27,84; tpl/a:index.twig:41; tpl/a:bin.twig:4,30; tpl/a:panel_page.twig:4; tpl/a:_pick.twig:2; tpl/translations/index.twig:29,79; tpl/partials/ai-translate-dialog.twig:30,65,86; src/Rbac/templates/new-permissions.twig:26; src/Rbac/templates/account.twig:20,25,36; src/Field/Types/MatrixFieldType.php:92,96; src/Field/Types/AssetFieldType.php:104; src/Members/templates/decision.twig:11,21 |
| Buttons | `btn-ghost` | admin.css:379,380,381; admin.js:118,119,120,255+1; tpl/_list_results.twig:84,92,107,110+2; tpl/form.twig:8,110,116,134+3; tpl/extensions.twig:9; tpl/_layout.twig:101; tpl/cache.twig:27; tpl/blocks/card.twig:13,14,15,18; tpl/blocks/unknown.twig:9; tpl/publishing/share.twig:6,7; tpl/redirects/index.twig:11,12,41,98+1; tpl/redirects/form.twig:43; tpl/ai/_tabs.twig:2,3,4; tpl/ai/run.twig:28,73; tpl/a:panel.twig:36,74,80; tpl/a:edit.twig:65,95,103; tpl/a:index.twig:45,49,62,76; tpl/a:bin.twig:41; tpl/translations/index.twig:81,95,97,98+1; tpl/partials/ai-translate-dialog.twig:64,87; src/Rbac/templates/new-permissions.twig:33; src/Rbac/templates/account.twig:31; src/Field/Types/MatrixFieldType.php:255,256,257; src/Field/Types/AssetFieldType.php:97 |
| Buttons | `btn-danger` | admin.css:383,384; admin.js:914; tpl/form.twig:199; tpl/a:panel.twig:166 |
| Buttons | `btn-sm` | admin.css:388; admin.js:49,1204; tpl/_list_results.twig:42,44,48,50+8; tpl/form.twig:219; tpl/extensions.twig:9; tpl/cache.twig:10,27,41,49+1; tpl/list.twig:25,49,53; tpl/redirects/index.twig:11,12,38,41+4; tpl/ai/_tabs.twig:2,3,4; tpl/ai/run.twig:46; tpl/translations/index.twig:29,52,79,81+4; tpl/partials/ai-translate-dialog.twig:30,86,87 |
| Buttons | `btn-icon` | admin.css:389,1034,1035; tpl/_list_results.twig:84,92; tpl/_layout.twig:101; tpl/blocks/card.twig:13,14,15,18; tpl/blocks/unknown.twig:9 |
| Split button | `folio-publish-at` | admin.css:816,825,830,831; tpl/form.twig:131 |
| Segmented | `folio-segmented` | admin.css:1604,1612,1628,1629+9; admin.js:1479; tpl/form.twig:8,214; src/Members/templates/decision.twig:24 |
| Segmented | `folio-theme-switch` | admin.css:228,235,246,247+1; tpl/_layout.twig:78,90 |
| Segmented | `folio-locale-switch` | admin.css:1886; tpl/_layout.twig:90 |
| Tabs | `folio-asset-tabs` | admin.css:1278,1279,1287,1288; tpl/a:_tabs.twig:2 |
| Badge | `folio-badge` | admin.css:867,881,882,883+9; tpl/_list_results.twig:73,74,75; tpl/form.twig:4; tpl/extensions.twig:33,34,42; tpl/seo-audit/index.twig:19,21,28,38+1; tpl/redirects/index.twig:88,89; tpl/ai/run-progress.twig:65; tpl/ai/run.twig:43; tpl/partials/dashboard-review.twig:12; src/Rbac/templates/account.twig:3,4; src/Field/Types/EnvFieldType.php:36; src/Members/templates/decision.twig:2 |
| Save state | `folio-save-state` | admin.css:895,896; admin.js:1420; tpl/form.twig:4,46; tpl/publishing/status.twig:1 |
| Save state | `folio-saved` | admin.css:863,864; tpl/a:panel.twig:2; tpl/a:edit.twig:13; tpl/a:index.twig:6 |
| Live dot | `folio-live-dot` | admin.css:1601; tpl/form.twig:213 |
| Chips | `folio-chip` | admin.css:1634,1635,1639,1641+17; admin.js:1553,1680,1683,1691; tpl/partials/checkbox-chips.twig:2,4,6; src/Rbac/Fields/RolesFieldType.php:19,61,64,66; src/Field/Types/SelectFieldType.php:111,113,115; src/Field/Types/RelationFieldType.php:85,88,89,171+2 |
| Chips | `folio-filter-chip` | admin.css:536,537,545,546+1; tpl/_list_results.twig:8,10 |
| Counts | `folio-nav-badge` | admin.css:172; tpl/_layout.twig:60,67 |
| Counts | `folio-count` | admin.css:885,887,888,898; tpl/form.twig:29,170; tpl/seo-audit/index.twig:39 |
| Languages | `folio-languages` | admin.css:912; ai-translate.css:3,4; tpl/form.twig:26,27 |
| Sidebar nav | `folio-nav` | admin.css:144,153,166,167+5; admin.js:1969; tpl/_layout.twig:63 |
| Sidebar nav | `folio-nav-section` | admin.css:189,190,203,204+12; admin.js:1964,1980; tpl/_layout.twig:66,67 |
| Site switcher | `folio-brand` | admin.css:127,137,1773; tpl/forgot.twig:6; tpl/password.twig:6; tpl/login.twig:33; tpl/_layout.twig:52,54 |
| User menu | `folio-user` | admin.css:254,276,286,296+3; tpl/_layout.twig:97,99 |
| User menu | `folio-sidebar-foot` | admin.css:221,1783; tpl/_layout.twig:77 |
| Breadcrumb | `folio-breadcrumb` | admin.css:335,342,343,344; tpl/form.twig:3 |
| Form | `form-group` | admin.css:306,618,619,630+13; admin.js:661,704; ai-translate.js:205,231; rbac/rbac.css:14; tpl/forgot.twig:17; tpl/password.twig:18,22; tpl/form.twig:81,85; tpl/account.twig:20,37,41,45; tpl/login.twig:39,43; tpl/redirects/form.twig:11,17,24,33; tpl/ai/run.twig:58,62,67; tpl/ai/tuning.twig:12,17; src/Rbac/Fields/RolesFieldType.php:59; src/Rbac/Fields/PermissionsFieldType.php:65; src/Field/Types/SelectFieldType.php:110; src/Field/Types/MatrixFieldType.php:68; src/Field/Types/RichTextFieldType.php:39; src/Field/Types/BooleanFieldType.php:41; src/Field/Types/AssetFieldType.php:78; src/Field/Types/RelationFieldType.php:74,93,166; src/Field/Types/EnvFieldType.php:34; src/Http/AdminController.php:1857; src/Members/templates/decision.twig:32 |
| Form | `form-label` | admin.css:620,630,953; tpl/blocks/field.twig:3; tpl/blocks/too_deep.twig:1; tpl/redirects/form.twig:12,25,34; tpl/ai/run.twig:59,63,68; tpl/ai/tuning.twig:13,19,22; src/Field/Types/EnvFieldType.php:35; src/Http/AdminController.php:1858 |
| Form | `form-help` | admin.css:630,756,786,954+4; ai-translate.css:25; tpl/blocks/field.twig:4; tpl/blocks/too_deep.twig:1; tpl/redirects/form.twig:14,29,30,39; tpl/ai/run-progress.twig:18,79; tpl/ai/run.twig:20,37,70; tpl/ai/status.twig:31; tpl/ai/tuning.twig:21; tpl/partials/checkbox-chips.twig:9; tpl/partials/ai-translate-dialog.twig:47; src/Rbac/Fields/RolesFieldType.php:70,76; src/Rbac/Fields/PermissionsFieldType.php:135; src/Field/Types/SelectFieldType.php:122; src/Field/Types/RichTextFieldType.php:51; src/Field/Types/BooleanFieldType.php:45; src/Field/Types/AssetFieldType.php:115; src/Field/Types/RelationFieldType.php:109,188; src/Field/Types/EnvFieldType.php:38; src/Http/AdminController.php:1860 |
| Form | `form-error` | admin.css:467,758,787; tpl/blocks/field.twig:6; tpl/redirects/form.twig:15,31; tpl/translations/index.twig:83; src/Rbac/Fields/RolesFieldType.php:73; src/Rbac/Fields/PermissionsFieldType.php:132; src/Field/Types/SelectFieldType.php:119; src/Field/Types/RichTextFieldType.php:54; src/Field/Types/BooleanFieldType.php:48; src/Field/Types/AssetFieldType.php:118; src/Field/Types/RelationFieldType.php:112,191 |
| Form | `has-error` | admin.css:759,760,761,762+6; tpl/blocks/card.twig:1; src/Rbac/Fields/RolesFieldType.php:59; src/Rbac/Fields/PermissionsFieldType.php:65; src/Field/Types/SelectFieldType.php:110; src/Field/Types/RichTextFieldType.php:39; src/Field/Types/BooleanFieldType.php:41; src/Field/Types/AssetFieldType.php:78; src/Field/Types/RelationFieldType.php:74,93,166 |
| Form | `form-required` | admin.css:628; src/Field/Types/RichTextFieldType.php:44; src/Field/Types/AssetFieldType.php:81; src/Field/Types/RelationFieldType.php:77,96,167 |
| Boolean | `folio-boolean` | admin.css:785,786,787,789+4; src/Field/Types/BooleanFieldType.php:41,43 |
| Boolean | `form-check` | admin.css:764,765,785,789+4; tpl/redirects/form.twig:18; src/Field/Types/BooleanFieldType.php:42 |
| Boolean | `form-radio` | admin.css:764,765,1828,1830+1; tpl/redirects/form.twig:20,21,36,37; tpl/ai/glossary.twig:27,34; tpl/ai/run-progress.twig:41; tpl/ai/run.twig:25,45,60,64+1 |
| Relation | `folio-relation` | admin.css:1142,1645,1678,1679+5; admin.js:1589,1614,1623,1631; src/Field/Types/RelationFieldType.php:74,82,93,166+3 |
| Asset field | `folio-asset-pick` | admin.css:1232; tpl/a:_pick.twig:2 |
| Asset field | `folio-asset-current` | admin.css:1346; tpl/a:panel.twig:54 |
| Matrix | `folio-matrix` | admin.css:1125,1126,1136,1137+1; admin.js:98,115,117; src/Field/Types/MatrixFieldType.php:68,70,72,85+3 |
| Readonly | `folio-readonly-value` | admin.css:757; src/Http/AdminController.php:1859 |
| Toolbar | `folio-list-toolbar` | admin.css:498,504,505,506; tpl/cache.twig:6,37,56; tpl/list.twig:16; tpl/redirects/index.twig:10; tpl/ai/_tabs.twig:1 |
| Toolbar | `folio-list-search` | admin.css:508,509,513; tpl/list.twig:17; tpl/redirects/index.twig:55; tpl/translations/index.twig:25 |
| Toolbar | `folio-toolbar` | admin.css:495,496,498,499+12; tpl/redirects/index.twig:52,53; tpl/translations/index.twig:6,8,9,17 |
| Filter menu | `folio-filter-menu` | admin.css:522,523,524,525; tpl/list.twig:24 |
| Filter menu | `folio-filter-panel` | admin.css:526,535; tpl/list.twig:26 |
| Filter menu | `folio-filter-range` | admin.css:534; tpl/list.twig:31 |
| Filter menu | `folio-filter-field` | admin.css:532; admin.js:1727; tpl/list.twig:28 |
| Filter menu | `folio-filter-label` | admin.css:533; tpl/list.twig:30,37,41 |
| Table | `folio-table` | admin.css:434,441,442,443+24; tpl/_list_results.twig:33,57,58; tpl/extensions.twig:25,26; tpl/cache.twig:14,15,66,67; tpl/seo-audit/index.twig:40,41; tpl/redirects/index.twig:19,20,69,70; tpl/ai/glossary.twig:10,11; tpl/ai/run-progress.twig:58,59; tpl/ai/status.twig:15,16,39,40; tpl/a:_list.twig:1,2; tpl/a:bin.twig:17,18; tpl/translations/index.twig:55,56; src/Rbac/Fields/PermissionsFieldType.php:83; src/Codes/templates/redemptions.twig:4 |
| Table | `folio-table-wrap` | admin.css:434,485; tpl/_list_results.twig:57; tpl/extensions.twig:25; tpl/cache.twig:14,66; tpl/seo-audit/index.twig:40; tpl/redirects/index.twig:19,69; tpl/ai/glossary.twig:10; tpl/ai/run-progress.twig:58; tpl/ai/status.twig:15,39; tpl/a:_list.twig:1; tpl/a:bin.twig:17; tpl/translations/index.twig:55; src/Rbac/Fields/PermissionsFieldType.php:83 |
| Table | `folio-table-frame` | admin.css:566; tpl/_list_results.twig:33 |
| Table | `folio-sort` | admin.css:550,551,552,554+2; tpl/_list_results.twig:63 |
| Table | `folio-row-link` | admin.css:461,462; tpl/_list_results.twig:73; tpl/redirects/index.twig:84 |
| Table | `folio-row-actions` | admin.css:465,466,467,469+3; tpl/_list_results.twig:77; tpl/extensions.twig:35,43; tpl/cache.twig:23; tpl/seo-audit/index.twig:52; tpl/redirects/index.twig:37,97; tpl/ai/run.twig:41; tpl/translations/index.twig:46,64 |
| Bulk bar | `folio-bulk-bar` | admin.css:567,575,583,585+8; tpl/_list_results.twig:36 |
| Bulk bar | `folio-bulk-menu` | admin.css:595,596,597; tpl/_list_results.twig:41 |
| Bulk bar | `folio-bulk-count` | admin.css:584; tpl/_list_results.twig:39 |
| Bulk bar | `folio-bulk-options` | admin.css:598,604,605,606; tpl/_list_results.twig:43 |
| Pager | `folio-pager` | admin.css:558,559,560,561; tpl/_list_results.twig:103,104,106,109; tpl/translations/index.twig:91,92,94 |
| Pager | `folio-pagination` | admin.css:1326; tpl/a:index.twig:74; tpl/a:bin.twig:39 |
| Empty | `folio-empty` | admin.css:472,488,489; tpl/_list_results.twig:18,19,24,25; tpl/forbidden.twig:5,6; tpl/extensions.twig:20,21; tpl/a:index.twig:65; tpl/a:bin.twig:14 |
| Empty | `folio-empty-icon` | admin.css:489; tpl/_list_results.twig:19,25; tpl/forbidden.twig:6; tpl/extensions.twig:21 |
| Alerts | `folio-alert-success` | admin.css:863; tpl/ai/run-progress.twig:34 |
| Alerts | `folio-alert-warning` | admin.css:861; tpl/redirects/index.twig:8 |
| Alerts | `folio-alert-error` | admin.css:862; tpl/forgot.twig:14; tpl/password.twig:15; tpl/account.twig:35; tpl/login.twig:37; tpl/ai/run-progress.twig:6,22; tpl/ai/run.twig:9; tpl/ai/status.twig:9; tpl/ai/tuning.twig:8 |
| Notice | `folio-notice` | admin.css:485,839,847,848+5; tpl/form.twig:17,20,25; tpl/extensions.twig:15,17; tpl/cache.twig:5,73; tpl/seo-audit/index.twig:7,32; tpl/redirects/index.twig:15,51; tpl/ai/glossary.twig:7; tpl/ai/run-progress.twig:36; tpl/ai/run.twig:13,57; tpl/ai/status.twig:11,13; tpl/ai/tuning.twig:7; tpl/a:panel.twig:3; tpl/a:edit.twig:14; tpl/a:index.twig:9; tpl/a:bin.twig:7; tpl/translations/index.twig:5; tpl/partials/dashboard-review.twig:19; src/Rbac/templates/new-permissions-dashboard.twig:2; src/Rbac/templates/new-permissions.twig:4,7 |
| Notice | `folio-page-notices` | admin.css:851,852; tpl/dashboard.twig:6; tpl/list.twig:13 |
| Locale errors | `folio-locale-errors` | admin.css:840,847,848,856; tpl/form.twig:34 |
| Toast | `folio-toast` | admin.css:1731,1741,1753,1755+2; admin.js:1199,1204,1434; tpl/_layout.twig:122 |
| Toast | `folio-toast-action` | admin.css:1755,1756; admin.js:1204 |
| Review | `folio-review` | admin.css:427; tpl/partials/dashboard-review.twig:4 |
| Review | `folio-ai-item-progress` | admin.css:1902,1903; tpl/partials/ai-item-progress.twig:4 |
| Revisions | `folio-revision` | admin.css:1073,1079,1088,1089+6; tpl/form.twig:169,171,173,174+2 |
| Audit | `folio-audit` | admin.css:1810,1811,1812,1813+8; tpl/seo-audit/index.twig:11,26,36 |
| Removal dialog | `folio-removal` | admin.css:1910,1919,1920,1921+13; admin.js:848,852,855,859+10 |
| Confirm | `data-confirm` | admin.js:720,722,725,735+5; tpl/_list_results.twig:48,53,90; tpl/form.twig:5,178,191,197; tpl/redirects/index.twig:99; tpl/a:panel.twig:13,72,78,164; src/Rbac/templates/account.twig:29 |
| Drawer | `folio-drawer` | admin.css:1495; admin.js:44,183; tpl/drawer_saved.twig:9 |
| Block card | `folio-block-card` | admin.css:957,965,966,967+13; tpl/blocks/card.twig:1; tpl/blocks/unknown.twig:1 |
| Block card | `folio-block-head` | admin.css:977,1000,1020,1031+2; admin.js:634,1046,1212; tpl/blocks/card.twig:4; tpl/blocks/unknown.twig:5 |
| Block card | `folio-block-handle` | admin.css:978,992,993,994+1; tpl/blocks/card.twig:5 |
| Block card | `folio-block-label` | admin.css:1021; admin.js:1046; tpl/blocks/card.twig:9; tpl/blocks/unknown.twig:6 |
| Block card | `folio-block-summary` | admin.css:834,1022; tpl/blocks/card.twig:10; tpl/blocks/unknown.twig:7 |
| Block card | `folio-block-toggle` | admin.css:1001,1017,1018,1019; tpl/blocks/card.twig:6 |
| Block card | `folio-block-actions` | admin.css:1030,1031,1032,1033+3; tpl/blocks/card.twig:12; tpl/blocks/unknown.twig:8 |
| Block card | `folio-block-body` | admin.css:974,975,1038,1039; admin.js:689; tpl/blocks/card.twig:21 |
| Blocks picker | `folio-blocks-picker` | admin.css:1041,1051; tpl/blocks/field.twig:15,16 |
| Blocks picker | `folio-blocks-group` | admin.css:1059,1060; tpl/blocks/field.twig:18,19 |
| Blocks picker | `folio-blocks-add` | admin.css:1067,1068; tpl/blocks/field.twig:21 |
| Preview | `folio-preview-bar` | admin.css:1591,1716,1717; tpl/form.twig:212 |
| Preview | `folio-preview-frame` | admin.css:1570,1696,1707; admin.js:336; tpl/form.twig:221 |
| Preview | `folio-preview-title` | admin.css:1600,1715; tpl/form.twig:213 |
| Preview | `folio-split` | admin.css:1519,1520,1527,1567+18; tpl/form.twig:10 |
| Preview | `folio-resizer` | admin.css:1546,1553,1565,1566+5; tpl/form.twig:210 |
| Preview | `folio-focus-toggle` | admin.css:1602; tpl/form.twig:219 |
| Share | `folio-share` | admin.css:729,933,1107,1108+3; admin.js:1383,1384; tpl/form.twig:8,15,103; tpl/publishing/share.twig:1,3,4,7 |
| Asset grid/list | `folio-asset-grid` | admin.css:1205,1213,1265; tpl/a:_grid.twig:1 |
| Asset grid/list | `folio-asset-list` | admin.css:1230,1251,1266; tpl/a:_list.twig:2; tpl/a:bin.twig:18 |
| Asset grid/list | `folio-asset-item` | admin.css:1359,1360,1361; admin.js:235; src/Field/Types/AssetFieldType.php:90 |
| Asset grid/list | `folio-asset-thumb` | admin.css:1214,1225,1226,1229+2; admin.js:241; tpl/a:_grid.twig:6,8; src/Field/Types/AssetFieldType.php:92 |
| Asset grid/list | `folio-asset-meta` | admin.css:1325; tpl/a:panel.twig:34,149,151; tpl/a:edit.twig:40,80 |
| Asset grid/list | `folio-asset-kind` | admin.css:1227; tpl/a:panel.twig:146; tpl/a:_grid.twig:4 |
| Asset detail | `folio-asset-form` | admin.css:1321,1322,1324; admin.js:1447,1457; tpl/a:panel.twig:94; src/Http/AssetLibraryController.php:769 |
| Asset detail | `folio-asset-panel` | admin.css:487,1289,1304; tpl/a:panel.twig:1 |
| Asset detail | `folio-asset-preview` | admin.css:1305,1330; tpl/a:panel.twig:23,31 |
| Asset detail | `folio-asset-usage` | admin.css:1306; tpl/a:panel.twig:124 |
| Asset detail | `folio-asset-versions` | admin.css:1344,1345; tpl/a:panel.twig:52 |
| Asset detail | `folio-asset-replace` | admin.css:1347,1348; tpl/a:panel.twig:56 |
| Asset detail | `folio-focal` | admin.css:1307,1308,1309; admin.js:1448; tpl/a:panel.twig:25,27 |
| Asset detail | `folio-swatch` | admin.css:1331,1332,1333,1338+3; tpl/a:panel.twig:39,41,42,43+2 |
| Translations save-all | `folio-translations-save-all` | admin.css:468; tpl/translations/index.twig:46 |

Notes on the table:

- `.folio-split` matched as a prefix, so it also counts `folio-split-form`, `-stage`.
- `.folio-nav` also matches `.folio-nav-*`; `.folio-drawer` shows only the bare class (the rest are `folio-drawer-*`).
- `data-confirm` (admin.js 720-796) is the hook for the proposed `.folio-confirm`; it is used on row, form and asset delete forms.
- Table rows: the per-row Edit button and trash live in `tpl/_list_results.twig` (84-110) and in the row
  cells of `cache`, `redirects/index`, `extensions`, `seo-audit`, `translations`, `ai/run`: nine templates carry a `.folio-row-actions` cell.
- `folio-languages` (the nav in the editor, `tpl/form.twig:26-27`) is the language switcher of the
  editor; the list's language column is `.folio-badge` with `is-ok|is-incomplete|is-outdated|is-unchecked`
  (`tpl/_list_results.twig:73`), not `.folio-languages`. COMPONENTS says "exists (in list)": it is not in the list.
- Editor language links use `.folio-language` (singular, admin.css:922), a class COMPONENTS does not name.

## 2. Existing classes COMPONENTS.md does not mention

Grouped. "Part of a mentioned family" means COMPONENTS names the family and the redesign has to restyle
these too. Line = first definition in the stylesheet.

| Group | Classes (first admin.css line unless noted) | Note |
|---|---|---|
| Shell and layout | `folio-shell` 87, `folio-sidebar` 114, `folio-main` 309, `folio-topbar` 311, `folio-topbar-title` 325, `folio-title-text` 333, `folio-actions` 346, `folio-content` 348, `folio-sr` 98, `folio-icon` 96 | `folio-shell` grid is `240px 1fr` (110); token `--w-sidebar` is 248. No subtitle slot in the topbar. |
| Sidebar parts | `folio-logo` (+`--xl`, `-page`, `-fold`, `-f`) 138-142, `folio-nav-label` 145, `folio-nav-items` 219, `folio-nav-marker` 208, `folio-nav-section-badge` 217, `folio-avatar` 264, `folio-user-email` 276, `folio-user-link` 286, `folio-logout` 285, `active` 169 | Part of the brand/nav/user families. |
| Account page | `folio-account`, `-who`, `-avatar`, `-name`, `folio-panel`, `folio-panel-title`, `folio-panel-body` (300-305, 1796) | `tpl/account.twig`, `src/Rbac/templates/account.twig`. |
| Cards and dashboard | `folio-card-grid` 392, `folio-card` 397, `folio-card-icon` 412, `-label` 423, `-count` 424, `folio-review-title/-type/-list/-jobs` 428-430 | Dashboard tiles. SCREENS replaces them with the ink band, "Needs you", content list. |
| Generic helpers | `folio-muted` 483, `folio-help` 305 (also `tpl/seo-audit/index.twig:8,10`), `folio-input-mono` 737, `folio-form-buttons` 485, `folio-alert` 485 (base of `-success/-warning/-error`) | |
| Toolbar parts | `folio-toolbar-fields/-field` 499-500, `folio-list-submit` 521, `folio-filter-chips` 536, `folio-ai`, `folio-ai-open` 503-507, `folio-sort-mark` 552, `is-idle` 554, `folio-pager-links/-gap` 559-561, `folio-bulk-cell/-clear` 582-594, `is-overlay` 575 | Parts of toolbar, filter, table, pager and bulk families. |
| Form skeleton | `folio-form` 612, `folio-fields` 614, `folio-editor` 615, `folio-form-actions` 616 (the floating publish box, 800), `folio-field` 621, `folio-visibility` 931 (+`-option` 764), `folio-form-extras` 1072, `folio-publish-actions` 1104, `folio-side` 1845, `has-side` 1847 | `folio-editor.has-side` is the existing side column (>= 1280px, 1846-1860) used by extension side panels and the old-addresses note. |
| Status and badge variants | `is-published` 882, `is-ok`, `is-incomplete` 884, `is-unchecked` 885, `is-outdated` 887, `is-scheduled` 889, `is-draft` 890, `is-critical` 891, `is-warning` 892, `is-notice` 893, `is-conflict` 896, `folio-fallback-hint` 933, `folio-shared` 933, `folio-outdated` 934, `folio-unchecked` 936, `folio-reference` 938, `folio-language` 922 | COMPONENTS only names `is-*` for the badge; the audit severities and the language-state variants are the same set. `is-changed` (883) is today's "changes not live" (warning, with a filled 6px dot via `.folio-badge::before`, 884). There is no `is-failed` variant, and `is-scheduled` is accent today (spec: info). |
| Notice parts | `folio-notice-list` 853, `folio-locale-errors` is mentioned, `folio-ai-pending` (`tpl/form.twig:25`) | `folio-notice` is also used for errors with `role="alert"` (see section 8). |
| SEO block | `folio-seo`, `-body`, `-help`, `-flags`, `-flag` 941-947 | The `▸ SEO & sharing` disclosure the editor tabs replace. |
| Block list | `folio-blocks` 951, `-head` 952, `-list` 955, `-errors` 1069, `-picker-label` 1051, `-group-label` 1060, `folio-block-chevron` 1019, `is-open` 966, `is-located` 968, `is-unknown` 973, `is-dragging`, `is-lifted`, `is-drop-before`, `is-drop-after` 996-999 | Drag state classes are set by admin.js (`tpl/blocks/*`, admin.js 634-689, 1046, 1212). |
| Revisions / share | `folio-revisions` 1073, `-list` 1090, `-date`, `-by`, `-changed` 1101-1103, `folio-share-label/-url` 1121-1122 | |
| Matrix / relation | `folio-matrix-rows/-row/-label/-controls/-add` 1125-1137, `folio-relation-item` 1142, `-filter` 1645, `-remote` 1678, `-results/-result` 1679-1684, `-more` 1689, `-empty` 1690, `folio-chips`, `folio-chips-group`, `-chip-label`, `-chip-remove` 1634-1676 | `folio-chip` carries relation items, roles chips (`src/Rbac/Fields/RolesFieldType.php`), select chips. |
| Trix | `trix-button-row/-group/-button/-active/-dialog/-input--dialog/-button--icon` 1156-1176 | Overrides of the vendored Trix CSS (`assets/vendor/trix.css`); markup is Trix-generated. |
| Asset library | `folio-assets-toolbar/-search/-upload/-unused/-views/-filter`, `-stack-heading`, `folio-asset`, `-label`, `-mini`, `-stack`, `-copy*`, `-original`, `-stale`, `-columns/-column`, `-note`, `-preview-image`, `-version*`, `-selection`, `-actions`, `-upload`, `folio-focal-picker/-marker`, `folio-swatches`, `-chip`, `-rgb`, `-color`, `-hex`, `is-dominant`, `is-copied`, `is-selected` (1182-1363) | Families named in COMPONENTS §4. `folio-asset-columns` is two columns at >= 60rem (1301). |
| Image editor | `folio-image-editor` (admin.css 487; `image-editor.css` 21-45): `-format-actions`, `-crop`, `-stage`, `-ratio`, `-format-list`, `-format`, `-format-info`, `-cut` | Cropper.js UI (`assets/vendor/cropper.min.css`). Not in COMPONENTS or SCREENS beyond the asset detail tab "Edit image". |
| Auth / ink | `folio-auth` 659, `-card` 730, `-card-fixed` 1480, `-art` 1376, `-pattern` 1387, `fg-fill`, `pc-1..pc-6` 1402-1407, `-drift` 1408, `-hello` 1414, `-word` 1429, `-line` 1436, `-caret` 1442, `-main` 1453, `-brand` 1468, `-title` 1469, `-lead` 1470, `-row` 1473, `-check` 1474, `-link` 1476, `-back` 1478, `-foot` 1481 | The reference for the ink layer (INK.md). `_auth_pattern.twig` defines the `fg-*` symbols inline. |
| Drawer | `folio-drawer-body` 87, `-panel` 1503, `-close` 1512, `-frame` 1513, `-content` 1515, `-heading` 1516 | |
| Split view | `folio-split-form` 1535, `-stage` 1572, `is-resizing` 1567, `is-device` 1707, `folio-view-switch` 1718 | |
| Toasts | `folio-toasts` 1731 | Container; toasts are created by admin.js 1199-1204, 1434. |
| Cache page | `folio-cache-opcache` 1801, `-pages` 1803, `-refresh` 1805 | |
| Audit | `folio-audit-summary` 1810, `-counts` 1813, `-check` 1815 | |
| Translations | `folio-table-form` 1825, `folio-cell-check` 1833, `folio-translations` 1837 | `folio-translations-save-all` (468) is the one COMPONENTS names. |
| Removal dialog parts | `folio-removal-dialog` 1910, `-line`, `-choices`, `-option`, `-search`, `-url`, `-results`, `-pick`, `-pick-meta`, `-none`, `-picked`, `-error`, `-footer` 1920-1937, `folio-old-addresses(-help|-more)` 1940-1942 | The dialog is built by admin.js (848-991) with `showModal`. |
| AI translate | `folio-languages-bar`, `folio-ai-review`, `-dialog`, `-title`, `-intro`, `-scope`, `-note`, `-live`, `-hint`, `-estimate`, `-failed`, `-error`, `-footer`, `-review-label/-text/-warnings/-actions/-hint`, `-diff` (`ai-translate.css` 3-50); `folio-ai-run`, `-run-fieldset`, `-tuning-new`, `-progress-count/-current/-section/-review` (admin.css 1894-1907) | `ai-translate-dialog.twig` is a `<dialog>`. COMPONENTS only names `.folio-ai-item-progress`. |
| Preview editor | `folio-pe-overlay`, `-box`, `-label`, `is-hover`, `is-field` (`preview-editor.css`) | Overlay drawn on the site's own page, see section 5. |
| RBAC | `rbac-permissions`, `-legend`, `-everything*`, `-switch(-input)`, `-unknown`, `-rest`, `-matrix`, `-cell`, `-cell-all`, `-none`, `-section`, `-others(-heading)`, `-group(-head|-label|-all)`, `-chips`, `-chip`, `is-locked` (`rbac.css` 3-117) | Own `rbac-` prefix, not `folio-`. SCREENS rewrites this matrix. |

## 3. COMPONENTS.md entries with no existing class (new)

| Entry | Name in COMPONENTS | Existing neighbour |
|---|---|---|
| Split button | `.folio-split-button` | `.folio-publish-at` (816) + two `btn` submits in `tpl/form.twig:131-140` |
| Unified tabs | `.folio-tabs` | `.folio-asset-tabs` (1278), `tpl/ai/_tabs.twig` (buttons in `.folio-list-toolbar`), extension panel tabs `data-folio-tabs` (`initTabBox` in admin.js) |
| Caret | `.folio-caret` | `.folio-auth-caret` (1442) and `@keyframes folio-blink` (1451), already defined, so tokens.css's copy is a duplicate |
| Search entry | `.folio-command` (*proposal*, not built in 0.73) | none |
| Rail | `.folio-rail` | none; `--w-rail` is new in tokens.css |
| Type icon tile | `.folio-type-icon` | `.folio-card-icon` (412), block icon in `blocks/card.twig` |
| Input affix | `.folio-input-affix` (slug prefix, copy button) | `.folio-input-mono` (737) |
| Confirm dialog | `.folio-confirm` (*proposal*, not built in 0.73) | `data-confirm` + `window.confirm`; the removal dialog is the only styled dialog |
| Shared menu | `.folio-menu` | `.folio-bulk-menu` (595), `.folio-filter-menu` (522), `<details>`-based popovers; no other menu exists |
| Loading state | `.btn.is-busy` | none; neither `aria-busy` nor htmx `htmx-request` is styled in `admin.css` or `admin.js` |
| Languages indicator restyle | `.folio-languages` | exists, but in the editor (not the list), see section 1 |
| Side panel, subtitle | `.folio-subtitle` and `.folio-side-panel` are not named in the handoff; SCREENS asks for a "one-line muted subtitle" and a 320px panel | `.folio-editor.has-side` / `.folio-side` (1845-1860) exist and are test-pinned |
| Ink classes | `.folio-ink`, `-marks`, `-line`, `-drift` (INK.md) | `.folio-auth-art/-pattern/-line/-drift` |
| Needs-you cards, recent changes, row "…" menu `.folio-row-actions` | named in SCREENS | `.folio-row-actions` exists, but holds Edit + trash buttons today |

## 4. Hard-coded colours outside `:root`

All Folio stylesheets, excluding lines inside `light-dark()` that use only tokens. `admin.css` `:root`
(lines 6-60) is excluded. `white-space` matches were dropped.

| File:line | Value | Used for |
|---|---|---|
| `admin.css:1316` | `#fff` (border) | Focal-point marker ring (`.folio-focal-marker`), must stay visible on any image |
| `admin.css:1318` | `rgb(0 0 0 / .55)`, `/ .4` | Focal marker outer ring and shadow |
| `admin.css:1340` | `rgb(0 0 0 / .12)` | Inset outline of colour swatches (`.folio-swatch-color`) on any colour |
| `admin.css:1382-1384` | `rgb(16 185 129 / .28)`, `rgb(129 140 248 / .16)`, `#0c1714` | Sign-in art glow + ink background (`.folio-auth-art`) |
| `admin.css:1385` | `#ecfdf5` | Sign-in text on ink |
| `admin.css:1402-1407` | `#34d399 #fbbf24 #fb7185 #7dd3fc #c4b5fd #bef264` | Six pattern hues `.pc-1..6` (become `--mark-1..6`) |
| `admin.css:1421` | `rgb(12 23 20 / .72)` | Sign-in "hello" panel background |
| `admin.css:1423` | `rgb(236 253 245 / .08)`, `rgb(0 0 0 / .45)` | Sign-in panel ring and shadow |
| `admin.css:1426-1428` | `#34d399`, `#a7f3d0`, `#052e1f` | Logo colours in the sign-in (`.folio-auth-hello .folio-logo-*`); same values the brand tile needs |
| `admin.css:1440` | `#a7f3d0` | Mono line on ink (`.folio-auth-line`, becomes `--ink-line`) |
| `admin.css:1448` | `#34d399` | Typing caret (`.folio-auth-caret`, becomes `--ink-accent`) |
| `admin.css:1490-1491` | `rgb(12 23 20 / .78)`, `#a7f3d0` | Sign-in footer pill on small screens |
| `admin.css:1704` | `#fff` | Preview iframe background (`.folio-preview-frame`): a site page is white regardless of the admin theme. Keep |
| `admin.css:1747-1748` | `light-dark(#1f1f1c, #ecece8)`, `light-dark(#f4f4f1, #1a1a17)` | Toast background and text (own inverse pair, not `--c-inverse`) |
| `admin.css:1755-1756` | `light-dark(#6ee7b7, #047857)`, `light-dark(rgb(255 255 255 / .1), rgb(0 0 0 / .06))`, `light-dark(#a7f3d0, #065f46)` | Toast action button and hover |
| `preview-editor.css:24` | `rgb(4 120 87 / .5)` | Hover outline of a block in the preview |
| `preview-editor.css:25` | `#047857`, `rgb(4 120 87 / .15)` | Selected block outline and halo |
| `preview-editor.css:27` | `#047857`, `rgb(4 120 87 / .07)` | Field highlight in the preview |
| `preview-editor.css:35-36` | `#047857`, `#fff` | Block label chip in the preview |
| `rbac/rbac.css:65` | `light-dark(#ffffff, var(--c-text))` | Switch knob |
| `rbac/rbac.css:66` | `rgb(0 0 0 / .25)` | Switch knob shadow |
| `ai-translate.css`, `image-editor.css` | none | Token-only |

`color-mix(in srgb, var(--c-*) …)` is used 17 times in `admin.css` (141, 322, 383, 540, 546, 586-593,
762, 812, 854-863, 1667): that is token-based and follows the new values; it is not listed. `assets/vendor/trix.css`
and `cropper.min.css` are vendor files with their own colours and are untouched.

## 5. Help banners that SCREENS moves into subtitles

SCREENS/COMPONENTS name Translations, Redirects, Cache. The same always-on `.folio-notice` pattern is on
other pages; listed too so the decision is explicit. The text is all in `lang/en|de|fr/folio.php`
(`folio.<key>` = nested array, e.g. `cache.intro` at `lang/en/folio.php:668`).

| Template:line | Translation key | Named in SCREENS? | Note |
|---|---|---|---|
| `tpl/cache.twig:5` | `folio.cache.intro` | yes (Cache) | Always on |
| `tpl/translations/index.twig:5` | `folio.translations.intro` | yes (Translations) | Always on; long (two sentences) |
| `tpl/redirects/index.twig:51` | `folio.redirects.intro` | yes (Redirects tab) | Always on |
| `tpl/redirects/index.twig:15` | `folio.redirects.not_found_intro` | yes (Not found tab) | Always on; four sentences, the longest text |
| `tpl/ai/glossary.twig:7` | `folio.ai_settings.glossary_intro` | no | Same pattern |
| `tpl/ai/tuning.twig:7` | `folio.ai_settings.tuning_intro` | no | Same pattern |
| `tpl/seo-audit/index.twig:8,10` | `folio.audit.intro` | no | A `<p class="folio-help">`, not a notice |
| `tpl/extensions.twig:15,17` | `folio.extensions.changes`, `.read_only` | no | Mode-dependent info, contains `<code>`; stays a notice |
| `tpl/seo-audit/index.twig:7` | `folio.audit.never_run` | no | Conditional state, stays a notice |
| `tpl/cache.twig:73` | `folio.cache.opcache_off_web` | no | Conditional state, stays a notice |
| `tpl/ai/status.twig:11,13` | `folio.ai_settings.switched_off(_ready)` | no | Conditional state, stays a notice |
| `tpl/partials/dashboard-review.twig:19` | `folio.ai_queue.no_worker` | no | Conditional state, stays a notice |
| `tpl/ai/run.twig:13,57` | `folio.ai_run.*` | no | Conditional state |

Other `.folio-notice` uses are warnings or errors (`role="alert"`): `tpl/form.twig:17,20`, `tpl/assets/panel.twig:3`,
`edit.twig:14`, `index.twig:9`, `bin.twig:7`, `tpl/ai/run-progress.twig:36`. Extension notices
(`#[ListNotice]`, `#[DashboardNotice]`, `.folio-page-notices` at `tpl/list.twig:13`, `tpl/dashboard.twig:6`)
and the RBAC "new permissions" notice (`src/Rbac/templates/new-permissions*.twig`) use the same class.

## 6. `:root` variables: today vs tokens.css

Same names in both files; no variable of today's `:root` is dropped or renamed. Light-theme values
differ slightly (new warmer palette); the dark theme moves from neutral graphite to green-black ink.

**Added by tokens.css (NEW):** `--r-xl`; `--w-sidebar` (248px), `--w-rail` (56px), `--w-side-panel` (320px);
`--c-info`, `--c-info-soft`; `--c-type-page|reference|asset|member|shop` and their `-soft` (10 vars);
`--ink`, `--ink-fg`, `--ink-line`, `--ink-accent`, `--ink-glow`; `--mark-1..6`;
a second `@keyframes folio-blink` (admin.css already has one at 1451); the reduced-motion rule for `.folio-caret`, `.folio-auth-caret`, `.folio-auth-drift`.
(`--mark-*` are 6 more variables beyond the 5 `--ink*`.)

**Changed values** (same variable names): `--font-sans` and `--font-mono` (Inter and JetBrains Mono added at the front of the
stack; no `@font-face` is allowed in 0.73 so the system fallback renders), all `--c-bg … --c-text-subtle`,
`--c-accent*`, `--c-danger`, `--c-danger-soft`, `--c-warning(-soft)`, `--c-success(-soft)`,
`--c-neutral-soft`, `--c-focus-ring`, `--c-overlay`, `--shadow-md` (new two-layer value).
Unchanged: `--r-xs|sm|r|lg`, `--h-*`, `--c-danger-fg`, `--shadow-sm` is the same shape with a darker dark value, `--shadow-lg` re-weighted.

| Variable | Today (light, dark) | tokens.css (light, dark) | Effect |
|---|---|---|---|
| `--c-text-subtle` | `#8e8e86`, `#90929b` | `#6b6b64`, `#8b9a93` | Passes 4.5:1; muted text gets visibly darker everywhere |
| `--c-inverse` | `#262622`, `#46484e` | `#1b2320`, `#e9f0ec` | **Flips in dark mode**: the bulk bar and save-all bar become light in dark. Toast has its own pair (4.1) |
| `--c-inverse-fg` | `#f6f6f4`, `#f4f4f6` | `#e9f0ec`, `#121816` | Same flip |
| `--c-inverse-danger` | `#ffb4ab`, `#ffb4ab` | `#ffb4ab`, `#b42318` | Dark value now for a light surface |
| `--c-accent` (dark) | `#3fcf98` | `#34d399` | Sign-in emerald |
| `--c-accent-soft` / `--c-success-soft` (dark) | `#203a31` | `#143a2c` | |
| `--c-warning` | `#9a5a06`, `#fbbf24` | `#8a5100`, `#f5b94a` | |
| `--c-bg`, `--c-surface*`, `--c-border*`, `--c-row-hover` (dark) | graphite `#1e1f22 … #3a3b41` | green-black `#121816 … #26302b` | Every surface |

Variables used in `admin.css` that tokens.css does not define: none found (`--c-inverse*`, `--c-focus-ring`, `--c-overlay`, `--shadow-*` are all in both).
Hard-coded ink values in the auth block (section 4) duplicate the new `--ink*`/`--mark-*` and should read them.

## 7. Icons: `icons.md` vs `assets/icons.svg`

`assets/icons.svg` has 35 symbols: block icons `block text image list link quote code grid` and
`ui-dashboard ui-file ui-settings ui-users ui-key ui-up ui-down ui-copy ui-trash ui-x ui-plus ui-eye ui-logout ui-sun ui-moon ui-monitor ui-tablet ui-phone ui-chevron ui-external ui-search ui-upload ui-history ui-library ui-split ui-check ui-grip`.
Drawn with `stroke-width="1.5"` on each `<symbol>` (`ui-grip` is filled circles); icons.md asks for 1.75.

| icons.md id | Status | Note |
|---|---|---|
| `ui-search`, `ui-grip`, `ui-external`, `ui-copy`, `ui-key`, `ui-split` | exists | Geometry differs from the handoff paths; the stroke is 1.5. Keep (README: only add what is missing) |
| `ui-desktop`, `ui-tablet`, `ui-phone` | `ui-tablet`, `ui-phone` exist; `ui-desktop` is called `ui-monitor` | Used by the viewport switch (`tpl/form.twig:215`) |
| `ui-updown`, `ui-dots`, `ui-home`, `ui-undo`, `ui-refresh`, `ui-arrow`, `ui-filter`, `ui-calendar`, `ui-clock`, `ui-globe`, `ui-spark`, `ui-panel`, `ui-form`, `ui-shield`, `ui-warn`, `ui-info` | **missing** (16) | Add. `ui-filter` is not used in `tpl/list.twig` today |
| `mark-caret`, `mark-pilcrow`, `mark-brace`, `mark-block`, `mark-cursor`, `mark-squiggle`, `mark-plus`, `mark-ring`, `mark-dot` | **missing** in `icons.svg` | Exist inline in `tpl/_auth_pattern.twig:4-12` as `fg-caret`, `fg-pilcrow`, `fg-brace`, `fg-block`, `fg-cursor`, `fg-squiggle`, `fg-plus`, `fg-ring`, `fg-dot`; identical paths to icons.md |
| Other ids the design needs, not in icons.md | `ui-chevron` exists (nav, breadcrumb); `ui-x`, `ui-trash`, `ui-plus`, `ui-check`, `ui-eye` exist | |

## 8. Things that look hard to fit (candidates for adaptation, not decisions)

1. **Floating publish box and the split button.** `.folio-form-actions` (admin.css 800) lives inside the
   `<form>` at the end of `tpl/form.twig`; the topbar is in `_layout.twig`. A topbar Save/Publish needs the
   buttons outside the form (`form="…"`) or a block that renders inside it. `_publish_at` is a field of that form,
   and "Publish at…" moves it into a menu while it must still submit. Autosave targets `#folio-save-state` (`tpl/form.twig:46`).
2. **Two shells.** `folio-shell` is a fixed `240px` grid, the phone layout (admin.css 1759-1790, `max-width: 760px`)
   is specific to the sidebar, and the nav data is rendered once in `_layout.twig:60-80`. A rail needs the same
   nav in a second markup and an expand button that persists (`data-nav-save`).
3. **Editor side panel already exists in part.** `.folio-editor.has-side` (>= 1280px) carries extension panels
   (`position: 'side'`) and the "old addresses" note; `AdminPolishTest` pins `.folio-editor.has-side` and
   `min-width: 1280px`. Next to `.folio-split` (form + resizer + preview) three columns plus the 56px rail
   at 1440 leaves about 480px for the form, as the design intends; below that the layout has no fallback in SCREENS.
4. **Tabs Content / SEO & sharing** replace the `<details class="folio-seo">`; the editor already has a tab
   behaviour for extension panels (`data-folio-tabs`, `initTabBox`, tests assert it). Candidate: reuse it for `.folio-tabs`.
5. **`.folio-notice` is both help text and warning.** The always-on banners become subtitles, but the same class is
   used for scheduled/stale/error notices with `role="alert"`, extension notices, and RBAC notices; re-toning
   `.folio-notice` to info changes them all. `NoticeHooksTest` pins the `.folio-page-notices` and `.folio-notice-list` rules.
6. **Per-row actions into a "…" menu** touch nine templates and a delete form per row (`data-confirm`); there is
   no menu component yet (`.folio-menu` is new).
7. **Locale dots and the language indicator** need a per-language state `complete / partial / missing`; the data
   today is `missing|outdated|unchecked|ok` per language (`_list_results.twig:73`, `form.twig:27`). "Partial" has no source.
8. **Type icons and tints.** Content types have an icon and a label, no hue. The six `--c-type-*` hues need a mapping
   (family) per type; nav items (`folio_nav()`), dashboard cards, block cards and the picker all carry only an icon id.
9. **Systemfelder, Ownership card, revisions "N changes · compare", search-result preview, address with
   rename-keeps-redirect** need data the template may not have (read-only/system field list, diff count, SEO fields).
10. **Field types render HTML in PHP** (`src/Field/Types/*`, `src/Rbac/Fields/*`), so stepper, counter, input affix,
    date hint, relation rows with avatar and the asset field are PHP + CSS + admin.js changes, not template-only.
    Text counter needs a max-length source; the Number unit needs one too.
11. **Icon stroke 1.75.** `stroke-width="1.5"` is an attribute on each `<symbol>`; CSS on the outer `<svg>` cannot override it, so
    changing the weight means editing every symbol (35, 9 of them are block icons used by block types).
12. **Auth marks are inline.** `_auth_pattern.twig` references `#fg-*` of its own document; moving them to `icons.svg` as `mark-*`
    means `<use href="…/icons.svg#mark-*">` (external reference, works, but `fill`/`stroke`/`class` for `fg-fill` and `pc-N`
    must be on the `<use>` element). The auth CSS keys off `.folio-auth-pattern .pc-N`.
13. **Preview editor overlay** (`preview-editor.css`) is injected in the *site's own page*; Folio tokens do not exist
    there, so its `#047857` cannot become `var(--c-accent)` without defining tokens in that document.
14. **Toast undo countdown bar, `.btn.is-busy` spinner, caret animation** are new behaviours in `admin.js`
    (toast 1199-1204, 1434); the spinner and caret are CSS-only. `.folio-badge` already draws a 6px dot, so the badge restyle is mostly sizing.
15. **Removal dialog** is the only styled dialog and is built in admin.js 848-991; the `data-confirm` →
    `.folio-confirm` swap and ⌘K are proposals (not built, phase 8). Dashboard "Recent changes" and "Needs you
    / frequent 404s" need an activity source; revisions have who/when, redirects have hits.
16. **Fonts.** Inter and JetBrains Mono cannot be loaded (no `@font-face`, owner approval pending); mono text
    (`--font-mono`) will render in the system mono. Widths in the design (tables, slugs) are drawn in those fonts.
17. **Inverse flip in dark mode** (section 6): the bulk bar reads `--c-inverse*` through `color-mix` (admin.css 586-593), so it
    inverts automatically; the toast has its own `light-dark()` pair and does not.
18. **`h1` is 17px** (admin.css 84); SCREENS wants 20px/600 in the topbar and a 12px breadcrumb above it. `h1` is global (also used by
    the auth screens).
19. **Dashboard**: `tpl/dashboard.twig` is 24 lines of `folio-card` tiles; the ink band, Needs-you, content list by section and
    the review queue are new structure, partly fed by `dashboard-review.twig`, extension `#[DashboardNotice]`s and nav sections.
20. **RBAC matrix** (`src/Rbac/Fields/PermissionsFieldType.php`, `rbac.css` 140 lines, `rbac.js` 60 lines) is its own component set:
    "Neu" badge, "Nur neue (N)" filter, implied dashed ticks and column toggles are mostly new, and the "new permissions" notice
    (`src/Rbac/templates/new-permissions.twig`) already exists.
