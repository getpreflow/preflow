<?php

// Messages of preflow/rbac (delegation; staff account actions). A site changes single keys in its own lang/de/rbac.php.
// :patterns is the comma-separated list of the permissions the editor doesn't hold;
// :names the role names that no staff role has; :email a staff account's address.
return [
    'delegation' => [
        'grant' => 'Sie können keine Berechtigungen vergeben, die Sie selbst nicht haben: :patterns',
        'account' => 'Sie können kein Konto ändern, dessen Rollen Sie nicht vergeben können.',
        'last' => 'Mindestens ein Konto muss das Recht behalten, Teamkonten und Rollen zu verwalten.',
        'unknown' => 'Unbekannte Rolle: :names.',
    ],    'staff' => [
        'invited' => 'Einladung an :email gesendet.',
        'invite_failed' => 'Das Konto ist gespeichert, aber es wurde keine Einladung gesendet. Prüfen Sie die Mail-Einrichtung (config/notify.php) und verwenden Sie dann «Einladung senden».',
        'mail_failed' => 'Die Mail konnte nicht gesendet werden. Prüfen Sie die Mail-Einrichtung (config/notify.php).',
        'no_sign_in' => 'Teamkonten können sich auf dieser Website noch nicht anmelden: Der Standard-Guard in config/auth.php hat keinen Provider für Teamkonten.',
        'has_password' => 'Dieses Konto hat bereits ein Passwort. Senden Sie stattdessen einen Link zum Zurücksetzen.',
        'inactive' => 'Dieses Konto ist deaktiviert. Aktivieren Sie es zuerst.',
        'reset_sent' => 'Link zum Zurücksetzen an :email gesendet.',
        'disabled' => 'Konto deaktiviert. Seine Sitzungen und Links sind beendet.',
        'enabled' => 'Konto aktiviert.',
    ],
];
