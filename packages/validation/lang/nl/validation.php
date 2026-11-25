<?php

// Messages of preflow/validation's rules (scope public) in this language. The keys and the
// placeholders (:min, :max, :step, :values) are those of lang/en/validation.php.
return [
    'required' => 'Dit veld is verplicht.',
    'email' => 'Moet een geldig e-mailadres zijn.',
    'url' => 'Moet een geldige URL zijn.',
    'numeric' => 'Moet een getal zijn.',
    'integer' => 'Moet een geheel getal zijn.',
    'min' => [
        'number' => 'Moet minimaal :min zijn.',
        'string' => 'Het aantal tekens moet minimaal :min zijn.',
    ],
    'max' => [
        'number' => 'Mag maximaal :max zijn.',
        'string' => 'Het aantal tekens mag maximaal :max zijn.',
    ],
    'between' => 'Moet tussen :min en :max liggen.',
    'step' => 'Moet een veelvoud van :step zijn.',
    'in' => 'Moet een van de volgende waarden zijn: :values.',
    'regex' => 'Het formaat is ongeldig.',
    'slug' => [
        'segment' => 'Alleen kleine letters a–z, cijfers en koppeltekens daartussen (nooit twee achter elkaar), bijv. ‘over-ons’.',
        'path' => 'Alleen kleine letters a–z, cijfers, koppeltekens en schuine strepen daartussen (nooit twee achter elkaar), bijv. ‘docs/over-ons’.',
    ],
    'confirmed' => 'De bevestiging komt niet overeen.',
    'unique' => 'Deze waarde is al in gebruik.',
];
