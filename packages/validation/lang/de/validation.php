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
];
