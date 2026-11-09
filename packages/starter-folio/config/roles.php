<?php

// Staff roles (preflow/roles). A staff account's roles come from its `roles` field.
// Permissions are "<type>.<action>" (view, create, edit, publish, delete) for each Folio type,
// plus Folio's own (folio.dashboard, assets.*, ...). '*' grants everything, including the
// screens only an admin should see (extensions, cache, redirects, translations).
return [
    'staff' => [
        'admin' => ['*'],
        // jobs_page, job_page and company_page are the page texts under Pages. news is a type the
        // starter adds later on; a permission for a type that does not exist yet grants nothing.
        'editor' => [
            'folio.dashboard',
            'page.*',
            'news.*',
            'job.*',
            'company.*',
            'jobs_page.*',
            'job_page.*',
            'company_page.*',
            'assets.view',
            'assets.upload',
            'assets.edit',
        ],
    ],
];
