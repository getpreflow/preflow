<?php

// Admin-Texte von preflow/members (Folio, in der Du-Form wie Folio; die öffentlichen Texte stehen in members.php).
// Eine Site ändert einzelne Schlüssel in ihrer lang/de/members-admin.php.
// types/sections/fields: die Bezeichnungen aus models/member.json und dem Einstellungsformular (Preflow\Data\ModelLabelKeys).
return [
    'types' => [
        'member' => ['label' => 'Mitglieder', 'singular' => 'Mitglied', 'empty' => 'Noch keine Mitglieder.'],
        'members_settings' => ['label' => 'Mitglieder-Einstellungen', 'singular' => 'Mitglieder-Einstellungen'],
    ],
    'sections' => [
        'members' => 'Mitglieder',
    ],
    'fields' => [
        'member' => [
            'email' => ['label' => 'E-Mail'],
            'name' => ['label' => 'Name'],
            'language' => ['label' => 'Sprache'],
            'registered_at' => ['label' => 'Registriert am'],
            'email_confirmed_at' => ['label' => 'E-Mail bestätigt am'],
            'decided_at' => ['label' => 'Entschieden am'],
            'locked_at' => ['label' => 'Gesperrt am'],
            'last_login_at' => ['label' => 'Letzte Anmeldung'],
            'roles' => ['label' => 'Rollen'],
        ],
        'members_settings' => [
            'registration_open' => ['label' => 'Registrierung offen'],
            'notice_email' => ['label' => 'Über wartende Mitglieder benachrichtigen', 'help' => 'Adresse, die eine Mail bekommt, wenn jemand auf die Freigabe wartet'],
            'accept_message' => ['label' => 'Nachricht beim Annehmen', 'help' => 'Im Bereich „Entscheidung“ vorausgefüllt; leer heißt: der Standardtext, der im Feld steht. {name} und {login_url} werden eingesetzt.'],
            'decline_message' => ['label' => 'Nachricht beim Ablehnen', 'help' => 'Im Bereich „Entscheidung“ vorausgefüllt; leer heißt: der Standardtext, der im Feld steht. {name} wird eingesetzt.'],
        ],
    ],
    'list' => ['state' => 'Status'],
    'states' => [
        'unconfirmed' => 'Unbestätigt',
        'waiting' => 'Wartet',
        'member' => 'Mitglied',
        'declined' => 'Abgelehnt',
    ],
    'decision' => [
        'title' => 'Entscheidung',
        'registered' => 'Registriert am :date',
        'unconfirmed' => 'Noch nicht bestätigt – nichts zu entscheiden.',
        'accept' => 'Annehmen',
        'decline' => 'Ablehnen',
        'accept_send' => 'Annehmen und senden',
        'decline_send' => 'Ablehnen und senden',
        'message_to' => 'Nachricht an :name',
        'sent_in' => 'Wird auf :language gesendet. Die Vorlage kommt aus den Mitglieder-Einstellungen; du kannst sie frei bearbeiten.',
        'empty_message' => 'Die Nachricht ist leer.',
        'cant_accept' => ':state: Annehmen nicht möglich – nichts wurde geändert.',
        'cant_decline' => ':state: Ablehnen nicht möglich – nichts wurde geändert.',
        'mail_failed' => 'Die Mail konnte nicht gesendet werden – nichts wurde geändert.',
        'languages' => [
            'en' => 'Englisch',
            'de' => 'Deutsch',
            'fr' => 'Französisch',
            'it' => 'Italienisch',
            'es' => 'Spanisch',
            'nl' => 'Niederländisch',
            'pt' => 'Portugiesisch',
            'rm' => 'Rätoromanisch',
        ],
    ],
    'errors' => [
        'email_taken' => 'Ein anderes Mitglied verwendet diese E-Mail-Adresse bereits.',
    ],
    'permissions' => [
        'access' => 'Mitgliederbereich nutzen',
        'decide' => 'Mitglieder annehmen oder ablehnen',
    ],
    'extension' => ['label' => 'Mitglieder'],
    'roles' => [
        'members' => [
            'pending' => 'Wartet auf Freigabe',
            'member' => 'Mitglied',
            'rejected' => 'Abgelehnt',
        ],
    ],
];
