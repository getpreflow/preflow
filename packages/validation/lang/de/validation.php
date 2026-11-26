<?php

// Meldungen der Regeln von preflow/validation (öffentlich). In einem Render folgen sie dessen
// Sprache, auch auf öffentlichen Seiten (seit 0.84.2); ausserhalb jedes Renders und für einen
// Validator ohne Translator gelten die englischen Texte. Eine Site ändert einzelne Schlüssel in
// ihrer lang/de/validation.php und ergänzt eine Sprache, die das Paket nicht mitbringt, mit
// lang/{locale}/validation.php. Fehlt der Text einer Sprache, gilt die Rückfallsprache der Site,
// dann Englisch.
return [
    'required' => 'Dieses Feld ist erforderlich.',
    'email' => 'Muss eine gültige E-Mail-Adresse sein.',
    'url' => 'Muss eine gültige URL sein.',
    'numeric' => 'Muss eine Zahl sein.',
    'integer' => 'Muss eine ganze Zahl sein.',
    'min' => [
        'number' => 'Muss mindestens :min sein.',
        'string' => 'Muss mindestens :min Zeichen lang sein.',
    ],
    'max' => [
        'number' => 'Darf höchstens :max sein.',
        'string' => 'Darf höchstens :max Zeichen lang sein.',
    ],
    'between' => 'Muss zwischen :min und :max liegen.',
    'step' => 'Muss ein Vielfaches von :step sein.',
    'in' => 'Muss eines davon sein: :values.',
    'regex' => 'Das Format ist ungültig.',
    'slug' => [
        'segment' => 'Nur Kleinbuchstaben a–z, Ziffern und einzelne Bindestriche dazwischen, z. B. «ueber-uns».',
        'path' => 'Nur Kleinbuchstaben a–z, Ziffern, einzelne Bindestriche und Schrägstriche dazwischen, z. B. «doku/ueber-uns».',
    ],
    'confirmed' => 'Die Bestätigung stimmt nicht überein.',
    'unique' => 'Dieser Wert ist bereits vergeben.',
    'phone' => 'Muss eine Telefonnummer sein: Ziffern, wahlweise mit + am Anfang und Leerzeichen, z. B. +41 44 123 45 67.',
    'color' => 'Muss eine Farbe in Hex-Schreibweise sein, z. B. #1a2b3c (oder #1a2b3c80 mit Transparenz).',
    'color_palette' => 'Wähle eine der angebotenen Farben: :values.',
    'json' => 'Muss gültiges JSON sein, z. B. {"name": "wert"} oder [1, 2, 3].',
    'phone_region' => 'Das Land dieser Nummer ist nicht erkennbar: Beginne sie mit + und der Landesvorwahl, z. B. +41 44 123 45 67.',
    'datetime' => 'Muss ein Datum mit Uhrzeit sein, im Editor z. B. 2026-03-29 14:30 (gespeichert in UTC als 2026-03-29T12:30:00Z).',
    'datetime_gap' => ':value gibt es in :zone nicht (die Uhr wird vorgestellt). Wähle eine andere Zeit.',
    'time' => 'Muss eine Uhrzeit mit Stunden und Minuten sein, z. B. 14:30.',
    'daterange' => 'Muss ein Zeitraum mit Anfang und Ende sein, z. B. :example.',
    'daterange_order' => 'Das Ende (:end) liegt vor dem Anfang (:start). Wähle ein Ende am oder nach dem :start.',
    'link' => [
        'kind' => 'Wähle, worauf der Link zeigt: :kinds.',
        'target' => 'Wähle oder gib ein, worauf der Link zeigt.',
        'type' => 'Dieser Link kann nicht auf einen Eintrag vom Typ :refused zeigen; wähle einen von: :types.',
        'url' => 'Muss eine Webadresse sein, die mit https:// oder http:// beginnt, oder ein Pfad dieser Website, der mit / beginnt.',
    ],
];
