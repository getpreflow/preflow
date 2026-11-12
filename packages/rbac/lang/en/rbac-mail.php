<?php

// The mails of staff accounts (Preflow\Rbac\Staff\StaffMail): {name}, {site}, {link}. A site
// changes single texts in its own lang/{locale}/rbac-mail.php. *_without_site: no app.name, no app.url.
return [
    'invite' => [
        'subject' => 'Your account for {site}',
        'action' => 'Accept the invitation',
        'body' => "Hi {name},\n\nyou were invited to edit {site}. Choose your password here: {link}\n\nThe link works for 7 days.\n\nKind regards\n{site}",
        'subject_without_site' => 'Your account for the website',
        'body_without_site' => "Hi {name},\n\nyou were invited to edit the website. Choose your password here: {link}\n\nThe link works for 7 days.\n\nKind regards",
    ],
    'reset' => [
        'subject' => 'Reset your password for {site}',
        'action' => 'Set your password',
        'body' => "Hi {name},\n\nset a new password for {site} here: {link}\n\nThe link works for one hour. If you didn't ask for this, ignore this mail.\n\nKind regards\n{site}",
        'subject_without_site' => 'Reset your password for the website',
        'body_without_site' => "Hi {name},\n\nset a new password for the website here: {link}\n\nThe link works for one hour. If you didn't ask for this, ignore this mail.\n\nKind regards",
    ],
];
