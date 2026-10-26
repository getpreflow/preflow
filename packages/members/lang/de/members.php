<?php

// Die Texte der Mitgliederseiten und -mails, in der Sie-Form. Eine Site ändert einzelne Schlüssel in ihrer lang/de/members.php.
// :name-Parameter füllt t(), {name}-Platzhalter in den Mails der Mailer.
return [
    // The page title and the tag on the status pages.
    'label' => 'Mitglieder',
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
        'text' => 'Neue Registrierungen sind im Moment pausiert. Wenn Sie bereits ein Konto haben, können Sie sich anmelden.',
    ],
    'errors' => [
        'login' => 'E-Mail oder Passwort stimmt nicht.',
        'summary' => 'Bitte prüfen Sie die markierten Felder.',
        'name' => 'Bitte geben Sie Ihren Namen ein.',
        'email' => 'Bitte geben Sie eine gültige E-Mail-Adresse ein.',
        'password' => 'Verwenden Sie mindestens :min Zeichen.',
        'consent' => 'Bitte stimmen Sie zu, damit Ihr Konto angelegt werden kann.',
        'throttled' => 'Zu viele Versuche, bitte versuchen Sie es später noch einmal.',
    ],
    'inbox' => [
        'title' => 'Prüfen Sie Ihr Postfach',
        'text' => 'Ein Link ist an :email unterwegs. Klicken Sie darauf, um Ihre Adresse zu bestätigen.',
        'hint' => 'Der Link gilt :validity. Nichts angekommen? Schauen Sie im Spam-Ordner nach.',
    ],
    'confirm' => [
        'title' => 'E-Mail bestätigen',
        'text' => 'Ein Klick, dann sind Sie fertig.',
        'button' => 'E-Mail bestätigen',
        'used_title' => 'Dieser Link funktioniert nicht mehr',
        'used' => 'Dieser Link wurde schon benutzt oder ist abgelaufen. Sie können sich anmelden oder sich noch einmal registrieren.',
        'login' => 'Anmelden',
        'register' => 'Noch einmal registrieren',
    ],
    'thanks' => [
        'tag' => 'Wartet auf Freigabe',
        'title' => 'Danke für Ihr Interesse',
        'text' => 'Ihre E-Mail-Adresse ist bestätigt. Ihre Registrierung wird geprüft; Sie erhalten eine E-Mail, sobald entschieden ist.',
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
        'text' => 'Geben Sie Ihre E-Mail-Adresse ein, dann erhalten Sie einen Link für ein neues Passwort.',
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
        'expired' => 'Dieser Link wurde schon benutzt oder ist abgelaufen. Fordern Sie einen neuen an.',
        'again' => 'Neuen Link senden',
    ],
    'resend' => [
        'title' => 'Bitte bestätigen Sie zuerst Ihre E-Mail-Adresse',
        'text' => 'Ihr Passwort stimmt, aber Ihre Adresse ist noch nicht bestätigt. Lassen Sie sich den Link noch einmal schicken.',
        'button' => 'Link noch einmal senden',
    ],
    'account' => [
        'back' => 'Zurück',
        'title' => 'Ihr Konto',
        'changed' => 'Passwort geändert.',
        'deleted' => 'Ihr Konto ist gelöscht.',
        'current' => 'Aktuelles Passwort',
        'password_title' => 'Passwort ändern',
        'password_submit' => 'Neues Passwort speichern',
        'delete_title' => 'Konto löschen',
        'delete_text' => 'Das entfernt Ihr Konto und Ihre persönlichen Daten. Es lässt sich nicht rückgängig machen. Sie können sich jederzeit neu registrieren.',
        'confirm' => 'Ich verstehe, dass mein Konto damit gelöscht wird.',
        'delete_submit' => 'Mein Konto löschen',
        'errors' => [
            'current' => 'Das aktuelle Passwort stimmt nicht.',
            'delete_current' => 'Das aktuelle Passwort stimmt nicht.',
            'mismatch' => 'Die beiden Passwörter stimmen nicht überein.',
            'confirm' => 'Bitte bestätigen Sie, dass Sie verstehen, dass Ihr Konto damit gelöscht wird.',
        ],
    ],
    // Wie lange ein Link gilt ({validity} / :validity), aus confirm_ttl und reset_ttl.
    'validity' => [
        'hours' => '{1} eine Stunde|[2,*] :count Stunden',
        'minutes' => '{1} eine Minute|[2,*] :count Minuten',
    ],
    // Betreff und Text jeder Mail (siehe lang/en/members.php).
    'mail' => [
        'confirm' => [
            'subject' => 'Bestätigen Sie Ihre E-Mail-Adresse',
            'body' => "Guten Tag {name}\n\nBitte bestätigen Sie Ihre E-Mail-Adresse: {link}\n\nDer Link gilt {validity}. Falls Sie sich nicht angemeldet haben, ignorieren Sie diese Mail.",
        ],
        'existing' => [
            'subject' => 'Ihr Konto',
            'body' => "Guten Tag {name}\n\nJemand (hoffentlich Sie) wollte sich mit dieser Adresse registrieren, aber Sie haben bereits ein Konto.\n\nAnmelden: {login_url}\nPasswort vergessen? {reset_url}\nDer Link zum Zurücksetzen gilt {validity}.",
        ],
        'reset' => [
            'subject' => 'Setzen Sie Ihr Passwort zurück',
            'body' => "Guten Tag {name}\n\nHier legen Sie ein neues Passwort fest: {link}\n\nDer Link gilt {validity}. Falls Sie das nicht angefordert haben, ignorieren Sie diese Mail.",
        ],
        'notice' => [
            'subject' => '{name} wartet auf Freigabe',
            'body' => "{name} <{email}> hat die E-Mail-Adresse bestätigt und wartet auf Freigabe.{details}\n\nIn Folio öffnen: {folio_url}",
            // without a decision page to link (no admin such as Folio's)
            'body_without_link' => "{name} <{email}> hat die E-Mail-Adresse bestätigt und wartet auf Freigabe.{details}",
        ],
        'accepted' => [
            'subject' => 'Ihr Konto ist bereit',
            'body' => "Guten Tag {name}\n\nIhr Konto ist bereit. Hier melden Sie sich an: {login_url}\n\nFreundliche Grüsse",
        ],
        'declined' => [
            'subject' => 'Zu Ihrer Registrierung',
            'body' => "Guten Tag {name}\n\nVielen Dank für Ihr Interesse. Leider können wir Ihnen im Moment kein Konto geben.\n\nFreundliche Grüsse",
        ],
    ],
];
