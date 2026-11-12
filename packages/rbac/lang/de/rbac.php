<?php

// Texte des Kerns von preflow/rbac: Ablehnungen bei Teamkonten und Rollen (StaffAccounts, die
// Konsolenbefehle, die Formulare eines Admins), die Rollen, die es anlegt. Die eigenen Texte eines Admins
// sind eine eigene Gruppe (Folio: rbac-admin). Eine Site ändert einzelne Schlüssel in ihrer lang/de/rbac.php.
// :patterns ist die kommagetrennte Liste der Berechtigungen, die der Bearbeiter nicht hat;
// :names die Rollennamen, die keine Teamrolle hat.
return [
    'delegation' => [
        'grant' => 'Du kannst keine Berechtigungen vergeben, die du selbst nicht hast: :patterns',
        'account' => 'Du kannst kein Konto ändern, dessen Rollen du nicht vergeben kannst.',
        'last' => 'Mindestens ein Konto muss das Recht behalten, Teamkonten und Rollen zu verwalten.',
        'unknown' => 'Unbekannte Rolle: :names.',
    ],
    'staff' => [
        'no_sign_in' => 'Teamkonten können sich auf dieser Website noch nicht anmelden: Der Standard-Guard in config/auth.php hat keinen Provider für Teamkonten.',
        'no_app_url' => 'Setze app.url, um Anmeldelinks zu senden.',
        'config_email' => 'Diese E-Mail-Adresse gehört einem konfigurierten Admin.',
        'has_password' => 'Dieses Konto hat bereits ein Passwort. Sende stattdessen einen Link zum Zurücksetzen.',
        'inactive' => 'Dieses Konto ist deaktiviert. Aktiviere es zuerst.',
        'email_taken' => 'Ein anderes Konto verwendet diese E-Mail-Adresse bereits.',
        'email_invalid' => 'Das ist keine E-Mail-Adresse.',
        'password_short' => 'Das Passwort braucht mindestens :min Zeichen.',
        'no_link_page' => 'Es ist keine Seite zum Setzen eines Passworts eingerichtet; verwende staff:password oder installiere eine Verwaltung, die eine anbietet.',
        'no_type' => 'Diese Website hat keine Teamkonten: config/auth.php nennt keinen User-Provider der Klasse Preflow\\Auth\\Record\\RecordUserProvider mit \'type\' => \'staff\'.',
        'gone' => 'Dieses Konto gibt es nicht mehr.',
    ],
    'roles' => [
        'staff' => ['admin' => 'Administrator'],
    ],
];
