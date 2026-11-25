<?php

// Messages of preflow/validation's rules (scope public) in this language. The keys and the
// placeholders (:min, :max, :step, :values) are those of lang/en/validation.php.
return [
    'required' => 'Este campo es obligatorio.',
    'email' => 'Debe ser una dirección de correo electrónico válida.',
    'url' => 'Debe ser una URL válida.',
    'numeric' => 'Debe ser un número.',
    'integer' => 'Debe ser un número entero.',
    'min' => [
        'number' => 'Debe ser como mínimo :min.',
        'string' => 'El número de caracteres debe ser como mínimo :min.',
    ],
    'max' => [
        'number' => 'Debe ser como máximo :max.',
        'string' => 'El número de caracteres debe ser como máximo :max.',
    ],
    'between' => 'Debe estar entre :min y :max.',
    'step' => 'Debe ser un múltiplo de :step.',
    'in' => 'Debe ser uno de los siguientes valores: :values.',
    'regex' => 'El formato no es válido.',
    'slug' => [
        'segment' => 'Solo letras minúsculas a–z, números y guiones simples entre ellos, p. ej., «sobre-nosotros».',
        'path' => 'Solo letras minúsculas a–z, números, guiones simples y barras entre ellos, p. ej., «docs/sobre-nosotros».',
    ],
    'confirmed' => 'La confirmación no coincide.',
    'unique' => 'Este valor ya está en uso.',
];
