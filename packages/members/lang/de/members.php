<?php

// Die Texte der Mitgliederseiten und -mails. Eine Site ändert einzelne Schlüssel in ihrer lang/de/members.php.
// :name-Parameter füllt t(), {name}-Platzhalter in den Mails der Mailer.
return [
    'form' => [
        'name' => 'Name',
        'email' => 'E-Mail',
        'password' => 'Passwort',
        'password_hint' => 'Mindestens :min Zeichen.',
        'consent' => 'Ich bin einverstanden, dass meine Daten für dieses Konto verwendet werden (:link).',
        'privacy' => 'Datenschutz',
        'submit' => 'Konto erstellen',
        'registered' => 'Schon registriert?',
        'login' => 'Anmelden',
    ],
    'closed' => [
        'title' => 'Registrierung geschlossen',
        'text' => 'Neue Registrierungen sind im Moment pausiert. Wenn du schon ein Konto hast, kannst du dich anmelden.',
    ],
    'errors' => [
        'login' => 'E-Mail oder Passwort stimmt nicht.',
        'summary' => 'Bitte prüfe die markierten Felder.',
        'name' => 'Bitte gib deinen Namen ein.',
        'email' => 'Bitte gib eine gültige E-Mail-Adresse ein.',
        'password' => 'Nimm mindestens :min Zeichen.',
        'consent' => 'Bitte stimme zu, damit dein Konto angelegt werden kann.',
        'throttled' => 'Zu viele Versuche, versuch es später noch einmal.',
    ],
    'inbox' => [
        'title' => 'Schau in dein Postfach',
        'text' => 'Ein Link ist an :email unterwegs. Klick darauf, um deine Adresse zu bestätigen.',
        'hint' => 'Der Link gilt 24 Stunden. Nichts angekommen? Schau im Spam-Ordner nach.',
    ],
    'confirm' => [
        'title' => 'E-Mail bestätigen',
        'text' => 'Ein Klick, dann bist du fertig.',
        'button' => 'E-Mail bestätigen',
        'used_title' => 'Dieser Link funktioniert nicht mehr',
        'used' => 'Dieser Link wurde schon benutzt oder ist abgelaufen. Du kannst dich anmelden oder dich noch einmal registrieren.',
        'login' => 'Anmelden',
        'register' => 'Noch einmal registrieren',
    ],
    'thanks' => [
        'tag' => 'Warte auf Freigabe',
        'title' => 'Danke für dein Interesse',
        'text' => 'Deine E-Mail ist bestätigt. Deine Registrierung wird geprüft; du bekommst eine E-Mail, sobald entschieden ist.',
    ],
    'login' => [
        'title' => 'Anmelden',
        'submit' => 'Anmelden',
        'forgot' => 'Passwort vergessen?',
        'no_account' => 'Noch kein Konto?',
        'register' => 'Registrieren',
    ],
    'forgot' => [
        'title' => 'Passwort vergessen?',
        'text' => 'Gib deine E-Mail-Adresse ein, dann bekommst du einen Link für ein neues Passwort.',
        'submit' => 'Link senden',
        'sent' => 'Wenn es zu dieser Adresse ein Konto gibt, ist ein Link unterwegs.',
        'back' => 'Zurück zur Anmeldung',
    ],
    'reset' => [
        'title' => 'Neues Passwort wählen',
        'password' => 'Neues Passwort',
        'repeat' => 'Passwort wiederholen',
        'submit' => 'Passwort speichern',
        'mismatch' => 'Die beiden Passwörter stimmen nicht überein.',
        'expired_title' => 'Dieser Link ist abgelaufen',
        'expired' => 'Dieser Link wurde schon benutzt oder ist abgelaufen. Fordere einen neuen an.',
        'again' => 'Neuen Link senden',
    ],
    'resend' => [
        'title' => 'Bitte bestätige zuerst deine E-Mail',
        'text' => 'Dein Passwort stimmt, aber deine Adresse ist noch nicht bestätigt. Lass dir den Link noch einmal schicken.',
        'button' => 'Link noch einmal senden',
    ],
    'account' => [
        'title' => 'Dein Konto',
        'changed' => 'Passwort geändert.',
        'deleted' => 'Dein Konto ist gelöscht.',
        'current' => 'Aktuelles Passwort',
        'password_title' => 'Passwort ändern',
        'password_submit' => 'Neues Passwort speichern',
        'delete_title' => 'Konto löschen',
        'delete_text' => 'Das entfernt dein Konto und deine persönlichen Daten. Es lässt sich nicht rückgängig machen. Du kannst dich jederzeit neu registrieren.',
        'confirm' => 'Ich verstehe, dass mein Konto damit gelöscht wird.',
        'delete_submit' => 'Mein Konto löschen',
        'errors' => [
            'current' => 'Das aktuelle Passwort stimmt nicht.',
            'delete_current' => 'Das aktuelle Passwort stimmt nicht.',
            'mismatch' => 'Die beiden Passwörter stimmen nicht überein.',
            'confirm' => 'Bitte bestätige, dass du verstehst, dass dein Konto damit gelöscht wird.',
        ],
    ],
    // Betreff und Text jeder Mail (siehe lang/en/members.php).
    'mail' => [
        'confirm' => [
            'subject' => 'Bestätige deine E-Mail-Adresse',
            'body' => "Hallo {name},\n\nbitte bestätige deine E-Mail-Adresse: {link}\n\nDer Link gilt 24 Stunden. Falls du dich nicht angemeldet hast, ignoriere diese Mail.",
        ],
        'existing' => [
            'subject' => 'Dein Konto',
            'body' => "Hallo {name},\n\njemand (hoffentlich du) wollte sich mit dieser Adresse registrieren, aber du hast schon ein Konto.\n\nAnmelden: {login_url}\nPasswort vergessen? {reset_url}",
        ],
        'reset' => [
            'subject' => 'Setze dein Passwort zurück',
            'body' => "Hallo {name},\n\nhier legst du ein neues Passwort fest: {link}\n\nDer Link gilt eine Stunde. Falls du das nicht angefordert hast, ignoriere diese Mail.",
        ],
        'notice' => [
            'subject' => '{name} wartet auf Freigabe',
            'body' => "{name} <{email}> hat die E-Mail-Adresse bestätigt und wartet auf Freigabe.{details}\n\nIn Folio öffnen: {folio_url}",
        ],
        'accepted' => [
            'subject' => 'Dein Konto ist bereit',
            'body' => "Hallo {name},\n\ndein Konto ist bereit. Hier meldest du dich an: {login_url}\n\nFreundliche Grüsse",
        ],
        'declined' => [
            'subject' => 'Zu deiner Registrierung',
            'body' => "Hallo {name},\n\nvielen Dank für dein Interesse. Leider können wir dir im Moment kein Konto geben.\n\nFreundliche Grüsse",
        ],
    ],
];
