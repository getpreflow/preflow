<?php

// Seed roles for preflow/rbac. Written by `php preflow folio:install`; yours to change.
// `php preflow rbac:install` (and `migrate`, `folio:extensions:apply`) create each role that does
// not exist yet; after that the stored roles decide: edit them in Folio under Settings → Roles, or
// with `php preflow rbac:role`. A staff account's roles come from its `roles` field.
// '*' grants everything. A content type added later gets its "<type>.*" in Settings → Roles.
return [
    'staff' => [
        'admin' => ['*'],
        'editor' => ['__EDITOR__'],
    ],
];
