<?php

// preflow/publishing — copy to the app's config/publishing.php to change a default.
return [
    // Revisions kept per record; 0 or null keeps every one.
    'revisions' => 20,
    // Refuse to publish while a language the record is visible in lacks texts the default language has.
    'complete_languages' => false,
    // How long a share-preview link works, in seconds (7 days).
    'preview_ttl' => 604800,
];
