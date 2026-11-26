<?php

// Messages of preflow/validation's rules (scope public) in this language. The keys and the
// placeholders (:min, :max, :step, :values) are those of lang/en/validation.php.
return [
    'required' => 'Este campo é obrigatório.',
    'email' => 'Deve ser um endereço de e-mail válido.',
    'url' => 'Deve ser uma URL válida.',
    'numeric' => 'Deve ser um número.',
    'integer' => 'Deve ser um número inteiro.',
    'min' => [
        'number' => 'Deve ser no mínimo :min.',
        'string' => 'O número de caracteres deve ser no mínimo :min.',
    ],
    'max' => [
        'number' => 'Deve ser no máximo :max.',
        'string' => 'O número de caracteres deve ser no máximo :max.',
    ],
    'between' => 'Deve estar entre :min e :max.',
    'step' => 'Deve ser um múltiplo de :step.',
    'in' => 'Deve ser um dos seguintes valores: :values.',
    'regex' => 'O formato é inválido.',
    'slug' => [
        'segment' => 'Apenas letras minúsculas a–z, números e hífens simples entre eles, ex.: “sobre-nos”.',
        'path' => 'Apenas letras minúsculas a–z, números, hífens simples e barras entre eles, ex.: “docs/sobre-nos”.',
    ],
    'confirmed' => 'A confirmação não corresponde.',
    'unique' => 'Este valor já está em uso.',
    'phone' => 'Deve ser um número de telefone: dígitos, opcionalmente com + no início e espaços, p. ex. +41 44 123 45 67.',
    'color' => 'Deve ser uma cor em notação hexadecimal, p. ex. #1a2b3c (ou #1a2b3c80 com transparência).',
    'color_palette' => 'Escolha uma das cores oferecidas: :values.',
    'json' => 'Deve ser JSON válido, p. ex. {"nome": "valor"} ou [1, 2, 3].',
    'phone_region' => 'Não é possível saber a que país pertence este número: comece-o com + e o indicativo do país, p. ex. +41 44 123 45 67.',
    'datetime' => 'Deve ser uma data com hora, escrita p. ex. 2026-03-29 14:30 no editor (guardada em UTC como 2026-03-29T12:30:00Z).',
    'datetime_gap' => ':value não existe em :zone (os relógios avançam). Escolha outra hora.',
    'time' => 'Deve ser uma hora com horas e minutos, p. ex. 14:30.',
    'daterange' => 'Deve ser um intervalo de datas com início e fim, p. ex. :example.',
    'daterange_order' => 'O fim (:end) é anterior ao início (:start). Escolha um fim igual ou posterior a :start.',
    'link' => [
        'kind' => 'Escolha para onde o link aponta: :kinds.',
        'target' => 'Escolha ou digite para onde o link aponta.',
        'type' => 'Este link não pode apontar para um registro do tipo :refused; escolha um de: :types.',
        'url' => 'Deve ser um endereço web que comece com https:// ou http://, ou um caminho deste site que comece com /.',
    ],
];
