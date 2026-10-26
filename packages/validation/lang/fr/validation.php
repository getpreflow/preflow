<?php

// Messages des règles de preflow/validation (publics : les formulaires du site les utilisent aussi,
// dès qu’ils passent un traducteur). Un site change des clés dans son propre lang/fr/validation.php ;
// ce fichier n’agit que là où un traducteur est passé (l’admin de Folio le fait ; un formulaire du site doit en passer un).
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
    'in' => 'Doit être l’une de ces valeurs : :values.',
    'regex' => 'Le format n’est pas valide.',
    'confirmed' => 'La confirmation ne correspond pas.',
];
