<?php

// Admin-Texte von preflow/rbac (Folio, in der Du-Form: Delegation, Teamkonten, die Rollen). Eine Site
// ändert einzelne Schlüssel in ihrer lang/de/rbac.php.
// :patterns ist die kommagetrennte Liste der Berechtigungen, die der Bearbeiter nicht hat;
// :names die Rollennamen, die keine Teamrolle hat; :email die Adresse eines Teamkontos.
return [
    'delegation' => [
        'grant' => 'Du kannst keine Berechtigungen vergeben, die du selbst nicht hast: :patterns',
        'account' => 'Du kannst kein Konto ändern, dessen Rollen du nicht vergeben kannst.',
        'last' => 'Mindestens ein Konto muss das Recht behalten, Teamkonten und Rollen zu verwalten.',
        'unknown' => 'Unbekannte Rolle: :names.',
        'own' => 'Du würdest das Recht verlieren, Rollen zu bearbeiten.',
        'last_role' => 'Es bliebe keine Teamrolle übrig, die Rollen bearbeiten darf.',
        'duplicate' => 'Eine Rolle namens :name gibt es für „:population“ bereits.',
    ],
    'staff' => [
        'invited' => 'Einladung an :email gesendet.',
        'invite_failed' => 'Das Konto ist gespeichert, aber es wurde keine Einladung gesendet. Prüfe die Mail-Einrichtung (config/notify.php) und verwende dann „Einladung senden“.',
        'mail_failed' => 'Die Mail konnte nicht gesendet werden. Prüfe die Mail-Einrichtung (config/notify.php).',
        'no_sign_in' => 'Teamkonten können sich auf dieser Website noch nicht anmelden: Der Standard-Guard in config/auth.php hat keinen Provider für Teamkonten.',
        'no_app_url' => 'Setze app.url, um Anmeldelinks zu senden.',
        'config_email' => 'Diese E-Mail-Adresse gehört einem konfigurierten Admin.',
        'has_password' => 'Dieses Konto hat bereits ein Passwort. Sende stattdessen einen Link zum Zurücksetzen.',
        'inactive' => 'Dieses Konto ist deaktiviert. Aktiviere es zuerst.',
        'reset_sent' => 'Link zum Zurücksetzen an :email gesendet.',
        'disabled' => 'Konto deaktiviert. Seine Sitzungen und Links sind beendet.',
        'enabled' => 'Konto aktiviert.',
        'email_taken' => 'Ein anderes Konto verwendet diese E-Mail-Adresse bereits.',
    ],
    'account' => [
        'title' => 'Konto',
        'active' => 'Aktiv',
        'disabled' => 'Deaktiviert',
        'no_password' => 'Noch kein Passwort',
        'disabled_help' => 'Kann sich nicht anmelden. Seine Sitzungen und Links endeten mit dem Deaktivieren.',
        'invited_help' => 'Kann sich erst anmelden, wenn mit dem Einladungslink ein Passwort gesetzt wurde.',
        'active_help' => 'Meldet sich mit einem eigenen Passwort an.',
        'disable_confirm' => ':email deaktivieren? Seine Sitzungen und Links enden sofort.',
    ],
    'actions' => [
        'invite' => 'Einladung senden',
        'reset' => 'Link zum Zurücksetzen senden',
        'disable' => 'Deaktivieren',
        'enable' => 'Aktivieren',
    ],
    'matrix' => [
        'everything' => 'Alles',
        'everything_hint' => 'Erteilt jede Berechtigung, jetzt und in künftigen Erweiterungen (:pattern)',
        'unknown' => 'Unbekannt, wird beim Speichern entfernt: :names',
        'type' => 'Typ',
        'all' => 'Alle',
        'none' => 'Keine Berechtigung „:verb“ für :type',
        'other' => 'Weitere Berechtigungen',
        'other_group' => 'Weitere',
        'denied' => 'Das kannst du nicht vergeben',
    ],
    'roles_field' => [
        'not_defined' => ':role (nicht definiert)',
        'no_population' => 'Kein Personenkreis: Gib dem Typ „users“ oder dem Feld „population“.',
    ],
    'extension' => ['label' => 'Rollen'],
    'roles' => [
        'staff' => ['admin' => 'Administrator'],
    ],
    // types/fields/options: die Bezeichnungen aus models/staff.json und models/rbac_role.json (Preflow\Data\ModelLabelKeys).
    'types' => [
        'staff' => ['label' => 'Team', 'singular' => 'Teammitglied', 'empty' => 'Noch keine Teammitglieder.'],
        'rbac_role' => ['label' => 'Rollen', 'singular' => 'Rolle', 'empty' => 'Noch keine Rollen.'],
    ],
    'fields' => [
        'staff' => [
            'email' => ['label' => 'E-Mail'],
            'name' => ['label' => 'Name'],
            'roles' => ['label' => 'Rollen'],
            'active' => ['label' => 'Aktiv', 'help' => 'Ein inaktives Konto kann sich nicht anmelden.'],
            'last_login_at' => ['label' => 'Letzte Anmeldung'],
            'created_at' => ['label' => 'Erstellt'],
        ],
        'rbac_role' => [
            'name' => ['label' => 'Name'],
            'label' => ['label' => 'Bezeichnung'],
            'population' => ['label' => 'Personenkreis'],
            'permissions' => ['label' => 'Berechtigungen'],
        ],
    ],
    'options' => [
        'rbac_role' => [
            'population' => ['staff' => 'Team', 'members' => 'Mitglieder'],
        ],
    ],
];
