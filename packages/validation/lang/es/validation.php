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
    'phone' => 'Debe ser un número de teléfono: dígitos, opcionalmente con + al inicio y espacios, p. ej. +41 44 123 45 67.',
    'color' => 'Debe ser un color en formato hexadecimal, p. ej. #1a2b3c (o #1a2b3c80 con transparencia).',
    'color_palette' => 'Elige uno de los colores ofrecidos: :values.',
    'json' => 'Debe ser JSON válido, p. ej. {"nombre": "valor"} o [1, 2, 3].',
    'phone_region' => 'No se puede saber a qué país pertenece este número: empieza por + y el prefijo del país, p. ej. +41 44 123 45 67.',
    'datetime' => 'Debe ser una fecha con hora, escrita p. ej. 2026-03-29 14:30 en el editor (guardada en UTC como 2026-03-29T12:30:00Z).',
    'datetime_gap' => ':value no existe en :zone (los relojes se adelantan). Elige otra hora.',
    'time' => 'Debe ser una hora con horas y minutos, p. ej. 14:30.',
    'daterange' => 'Debe ser un rango de fechas con inicio y fin, p. ej. :example.',
    'daterange_order' => 'El fin (:end) es anterior al inicio (:start). Elige un fin igual o posterior a :start.',
    'link' => [
        'kind' => 'Elige a qué apunta el enlace: :kinds.',
        'target' => 'Elige o escribe a qué apunta el enlace.',
        'type' => 'Este enlace no puede apuntar a un registro de tipo :refused; elige uno de: :types.',
        'url' => 'Debe ser una dirección web que empiece por https:// o http://, o una ruta de este sitio que empiece por /.',
    ],
];
