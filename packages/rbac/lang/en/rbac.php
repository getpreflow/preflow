<?php

// Delegation messages of preflow/rbac. A site changes single keys in its own lang/en/rbac.php.
// :patterns is the comma-separated list of the permissions the editor doesn't hold;
// :names the role names that no staff role has.
return [
    'delegation' => [
        'grant' => "You can't grant permissions you don't have: :patterns",
        'account' => "You can't change an account with roles you can't grant.",
        'last' => 'At least one account must keep the right to manage staff and roles.',
        'unknown' => 'Unknown role: :names.',
    ],
];
