<?php

// Staff roles (preflow/roles). A staff account's roles come from its `roles` field.
// Permissions are "<type>.<action>" (view, create, edit, publish, delete) for each Folio type,
// plus Folio's own (folio.dashboard, assets.*, ...). '*' grants everything, including the
// screens only an admin should see (extensions, cache, redirects, translations).
return [
    'staff' => [
        'admin' => ['*'],
        // page holds the pages built from blocks (the home page among them), news the news posts;
        // jobs_page, job_page and company_page are the page texts under Pages.
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
    // Employers sign in on their own guards (population members), so these never open Folio.
    // The API asks for job.publish before it publishes; an account without the role posts drafts.
    'members' => [
        'employer' => ['job.publish'],
    ],
];
