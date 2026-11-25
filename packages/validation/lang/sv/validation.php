<?php

// Messages of preflow/validation's rules (scope public) in this language. The keys and the
// placeholders (:min, :max, :step, :values) are those of lang/en/validation.php.
return [
    'required' => 'Det här fältet är obligatoriskt.',
    'email' => 'Måste vara en giltig e-postadress.',
    'url' => 'Måste vara en giltig URL.',
    'numeric' => 'Måste vara ett tal.',
    'integer' => 'Måste vara ett heltal.',
    'min' => [
        'number' => 'Måste vara minst :min.',
        'string' => 'Måste vara minst :min tecken.',
    ],
    'max' => [
        'number' => 'Får vara högst :max.',
        'string' => 'Får vara högst :max tecken.',
    ],
    'between' => 'Måste vara mellan :min och :max.',
    'step' => 'Måste vara en multipel av :step.',
    'in' => 'Måste vara en av: :values.',
    'regex' => 'Formatet är ogiltigt.',
    'slug' => [
        'segment' => 'Endast små bokstäver a-z, siffror och enstaka bindestreck mellan dem, t.ex. "om-oss".',
        'path' => 'Endast små bokstäver a-z, siffror, enstaka bindestreck och snedstreck mellan dem, t.ex. "docs/om-oss".',
    ],
    'confirmed' => 'Bekräftelsen stämmer inte överens.',
    'unique' => 'Det här värdet används redan.',
];
