<?php

// Messages des règles de preflow/validation (publics). Dans un rendu, ils suivent la langue de
// celui-ci, sur une page publique aussi (depuis 0.84.2) ; hors de tout rendu, et pour un
// validateur créé sans traducteur, les textes anglais s’appliquent. Un site change des clés dans
// son propre lang/fr/validation.php et ajoute une langue que le paquet ne fournit pas avec
// lang/{locale}/validation.php. Sans texte pour une langue, la langue de repli du site s’applique,
// puis l’anglais.
return [
    'required' => 'Ce champ est obligatoire.',
    'email' => 'Doit être une adresse e-mail valide.',
    'url' => 'Doit être une URL valide.',
    'numeric' => 'Doit être un nombre.',
    'integer' => 'Doit être un nombre entier.',
    'min' => [
        'number' => 'Doit être supérieur ou égal à :min.',
        'string' => 'Doit contenir au moins :min caractères.',
    ],
    'max' => [
        'number' => 'Doit être inférieur ou égal à :max.',
        'string' => 'Doit contenir au plus :max caractères.',
    ],
    'between' => 'Doit être entre :min et :max.',
    'step' => 'Doit être un multiple de :step.',
    'in' => 'Doit être l’une de ces valeurs : :values.',
    'regex' => 'Le format n’est pas valide.',
    'slug' => [
        'segment' => 'Uniquement des lettres minuscules a–z, des chiffres et des tirets simples entre eux, p. ex. « a-propos ».',
        'path' => 'Uniquement des lettres minuscules a–z, des chiffres, des tirets et des barres obliques simples entre eux, p. ex. « docs/a-propos ».',
    ],
    'confirmed' => 'La confirmation ne correspond pas.',
    'unique' => 'Cette valeur est déjà utilisée.',
    'phone' => 'Doit être un numéro de téléphone : des chiffres, avec éventuellement un + au début et des espaces, p. ex. +41 44 123 45 67.',
    'color' => 'Doit être une couleur en notation hexadécimale, p. ex. #1a2b3c (ou #1a2b3c80 avec transparence).',
    'color_palette' => 'Choisissez l\'une des couleurs proposées : :values.',
    'json' => 'Doit être du JSON valide, p. ex. {"nom": "valeur"} ou [1, 2, 3].',
    'phone_region' => 'Impossible de savoir à quel pays appartient ce numéro : commencez-le par + et l\'indicatif du pays, p. ex. +41 44 123 45 67.',
    'datetime' => 'Doit être une date avec heure, saisie par ex. 2026-03-29 14:30 dans l’éditeur (enregistrée en UTC : 2026-03-29T12:30:00Z).',
    'datetime_gap' => ':value n’existe pas en :zone (les pendules avancent). Choisissez une autre heure.',
    'time' => 'Doit être une heure avec heures et minutes, par ex. 14:30.',
    'daterange' => 'Doit être une période avec un début et une fin, par ex. :example.',
    'daterange_order' => 'La fin (:end) précède le début (:start). La fin doit être postérieure ou égale à :start.',
    'embed' => 'Doit être l’adresse d’une vidéo sur :providers, par exemple https://www.youtube.com/watch?v=aqz-KE-bpKQ.',
    'embed_provider' => 'Les vidéos de :host ne peuvent pas être intégrées ici ; collez l’adresse d’une vidéo sur :providers.',
    'embed_poster' => 'L’affiche doit être une image de la médiathèque ; choisissez-en une ou retirez-la.',
    'table' => 'Doit être un tableau : une ligne d’en-tête facultative et des lignes de cellules de texte.',
    'table_columns' => 'Ce tableau a :count colonnes ; :max au maximum sont autorisées. Supprimez une colonne.',
    'table_rows' => 'Ce tableau a :count lignes ; :max au maximum sont autorisées. Supprimez une ligne.',
    'location' => 'Doit être un lieu avec une latitude et une longitude, par exemple 47.3769, 8.5417.',
    'location_lat' => 'La latitude doit être un nombre de -90 à 90 (le nord est positif), par exemple 47.3769.',
    'location_lng' => 'La longitude doit être un nombre de -180 à 180 (l’est est positif), par exemple 8.5417.',
    'link' => [
        'kind' => 'Choisissez la cible du lien : :kinds.',
        'target' => 'Choisissez ou saisissez la cible du lien.',
        'type' => 'Ce lien ne peut pas pointer vers une entrée de type :refused ; choisissez parmi : :types.',
        'url' => 'Doit être une adresse web commençant par https:// ou http://, ou un chemin de ce site commençant par /.',
    ],
];
