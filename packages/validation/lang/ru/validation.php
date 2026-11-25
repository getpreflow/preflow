<?php

// Messages of preflow/validation's rules (scope public) in this language. The keys and the
// placeholders (:min, :max, :step, :values) are those of lang/en/validation.php.
return [
    'required' => 'Это поле обязательно для заполнения.',
    'email' => 'Должно быть действительным адресом электронной почты.',
    'url' => 'Должно быть действительным URL.',
    'numeric' => 'Должно быть числом.',
    'integer' => 'Должно быть целым числом.',
    'min' => [
        'number' => 'Должно быть не меньше :min.',
        'string' => 'Должно содержать не менее :min символов.',
    ],
    'max' => [
        'number' => 'Должно быть не больше :max.',
        'string' => 'Должно содержать не более :max символов.',
    ],
    'between' => 'Должно быть от :min до :max.',
    'step' => 'Должно быть кратно :step.',
    'in' => 'Должно быть одним из: :values.',
    'regex' => 'Неверный формат.',
    'slug' => [
        'segment' => 'Только строчные буквы a-z, цифры и одиночные дефисы между ними, например "o-nas".',
        'path' => 'Только строчные буквы a-z, цифры, одиночные дефисы и косые черты между ними, например "docs/o-nas".',
    ],
    'confirmed' => 'Подтверждение не совпадает.',
    'unique' => 'Это значение уже занято.',
];
