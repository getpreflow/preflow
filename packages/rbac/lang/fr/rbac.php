<?php

// Delegation messages of preflow/rbac. A site changes single keys in its own lang/fr/rbac.php.
// :patterns is the comma-separated list of the permissions the editor doesn't hold.
return [
    'delegation' => [
        'grant' => "Vous ne pouvez pas accorder des autorisations que vous n'avez pas : :patterns",
        'account' => "Vous ne pouvez pas modifier un compte dont vous ne pouvez pas accorder les rôles.",
        'last' => "Au moins un compte doit conserver le droit de gérer les comptes de l'équipe et les rôles.",
    ],
];
