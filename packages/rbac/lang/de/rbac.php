<?php

// Delegation messages of preflow/rbac. A site changes single keys in its own lang/de/rbac.php.
// :patterns is the comma-separated list of the permissions the editor doesn't hold;
// :names the role names that no staff role has.
return [
    'delegation' => [
        'grant' => 'Sie können keine Berechtigungen vergeben, die Sie selbst nicht haben: :patterns',
        'account' => 'Sie können kein Konto ändern, dessen Rollen Sie nicht vergeben können.',
        'last' => 'Mindestens ein Konto muss das Recht behalten, Teamkonten und Rollen zu verwalten.',
        'unknown' => 'Unbekannte Rolle: :names.',
    ],
];
