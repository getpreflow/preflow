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
        'string' => 'Moet minimaal :min tekens lang zijn.',
    ],
    'max' => [
        'number' => 'Mag maximaal :max zijn.',
        'string' => 'Mag maximaal :max tekens lang zijn.',
    ],
    'between' => 'Moet tussen :min en :max liggen.',
    'step' => 'Moet een veelvoud van :step zijn.',
    'in' => 'Moet een van deze zijn: :values.',
    'regex' => 'De notatie is ongeldig.',
    'slug' => [
        'segment' => 'Alleen kleine letters a-z, cijfers en enkele koppeltekens ertussen, bijv. "over-ons".',
        'path' => 'Alleen kleine letters a-z, cijfers, enkele koppeltekens en schuine strepen ertussen, bijv. "docs/over-ons".',
    ],
    'confirmed' => 'De bevestiging komt niet overeen.',
    'unique' => 'Deze waarde is al in gebruik.',
];
