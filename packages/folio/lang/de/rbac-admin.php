<?php

// Admin-Texte von rbac (Folios eingebaute Erweiterung „rbac“, in der Du-Form: Teamkonten, die Rollen,
// der Berechtigungseditor, die Bezeichnungen der Modelle). Die Texte von preflow/rbac selbst
// (Ablehnungen, angelegte Rollen) sind seine Gruppe „rbac“. Eine Site ändert einzelne Schlüssel in
// ihrer lang/de/rbac-admin.php. :email ist die Adresse eines Teamkontos.
return [
    'delegation' => [
        'own' => 'Du würdest das Recht verlieren, Rollen zu bearbeiten.',
        'last_role' => 'Es bliebe keine Teamrolle übrig, die Rollen bearbeiten darf.',
        'duplicate' => 'Eine Rolle namens :name gibt es für „:population“ bereits.',
    ],
    'staff' => [
        'invited' => 'Einladung an :email gesendet.',
        'invite_failed' => 'Das Konto ist gespeichert, aber es wurde keine Einladung gesendet. Prüfe die Mail-Einrichtung (config/notify.php) und verwende dann „Einladung senden“.',
        'mail_failed' => 'Die Mail konnte nicht gesendet werden. Prüfe die Mail-Einrichtung (config/notify.php).',
        'reset_sent' => 'Link zum Zurücksetzen an :email gesendet.',
        'disabled' => 'Konto deaktiviert. Seine Sitzungen und Links sind beendet.',
        'enabled' => 'Konto aktiviert.',
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
    // Die neuen Berechtigungen aus einem Update: der Hinweis über der Rollenliste, seine Aktionen,
    // die Zeile im Dashboard (:count, „eins|mehrere“). :role ist die Bezeichnung einer Rolle.
    'new' => [
        'title' => 'Neue Berechtigungen',
        'intro' => 'Diese Berechtigungen sind mit einem Update dazugekommen. Keine Rolle bekommt sie automatisch.',
        'covered' => 'Schon in:',
        'pick' => 'Zu Rolle hinzufügen…',
        'add' => 'Hinzufügen',
        'dismiss' => 'Ausblenden',
        'dashboard' => ':count neue Berechtigung in Rollen zu prüfen|:count neue Berechtigungen in Rollen zu prüfen',
        'added' => 'Zu :role hinzugefügt.',
        'dismissed' => 'Ausgeblendet.',
        'no_role' => 'Wähle die Rolle, der du sie geben willst.',
        'unavailable' => 'Neue Berechtigungen lassen sich noch nicht prüfen: Führe preflow migrate aus.',
        'failed' => 'Die Rolle konnte nicht gespeichert werden. Der Fehler steht im Server-Log.',
    ],
    'extension' => ['label' => 'Rollen'],
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
