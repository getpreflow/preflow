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
        'string' => 'Måste innehålla minst :min tecken.',
    ],
    'max' => [
        'number' => 'Får vara högst :max.',
        'string' => 'Får innehålla högst :max tecken.',
    ],
    'between' => 'Måste vara mellan :min och :max.',
    'step' => 'Måste vara en multipel av :step.',
    'in' => 'Måste vara något av följande: :values.',
    'regex' => 'Formatet är ogiltigt.',
    'slug' => [
        'segment' => 'Endast små bokstäver a–z, siffror och bindestreck mellan dem (aldrig två i rad), t.ex. ”om-oss”.',
        'path' => 'Endast små bokstäver a–z, siffror samt bindestreck och snedstreck mellan dem (aldrig två i rad), t.ex. ”docs/om-oss”.',
    ],
    'confirmed' => 'Bekräftelsen stämmer inte överens.',
    'unique' => 'Det här värdet används redan.',
    'phone' => 'Måste vara ett telefonnummer: siffror, eventuellt med + först och mellanslag, t.ex. +41 44 123 45 67.',
    'color' => 'Måste vara en färg i hexadecimal form, t.ex. #1a2b3c (eller #1a2b3c80 med transparens).',
    'color_palette' => 'Välj en av de färger som erbjuds: :values.',
    'json' => 'Måste vara giltig JSON, t.ex. {"namn": "värde"} eller [1, 2, 3].',
    'phone_region' => 'Det går inte att se vilket land numret hör till: börja med + och landskoden, t.ex. +41 44 123 45 67.',
    'datetime' => 'Måste vara ett datum med tid i UTC, t.ex. 2026-03-29T12:30:00Z.',
    'datetime_gap' => ':value finns inte i :zone (klockorna går framåt). Välj en annan tid.',
    'time' => 'Måste vara en tid med timmar och minuter, t.ex. 14:30.',
    'daterange' => 'Måste vara ett datumintervall med start och slut, t.ex. :example.',
    'daterange_order' => 'Slutet (:end) ligger före starten (:start). Välj ett slut på eller efter :start.',
    'link' => [
        'kind' => 'Välj vad länken pekar på: :kinds.',
        'target' => 'Välj eller skriv vad länken pekar på.',
        'type' => 'Den här länken kan inte peka på en post av typen :refused; välj en av: :types.',
        'url' => 'Måste vara en webbadress som börjar med https:// eller http://, eller en sökväg på den här webbplatsen som börjar med /.',
    ],
];
