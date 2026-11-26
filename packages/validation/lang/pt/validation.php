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
    'link' => [
        'kind' => 'Escolha para onde o link aponta: :kinds.',
        'target' => 'Escolha ou digite para onde o link aponta.',
        'type' => 'Este link não pode apontar para um registro do tipo :refused; escolha um de: :types.',
        'url' => 'Deve ser um endereço web que comece com https:// ou http://, ou um caminho deste site que comece com /.',
    ],
];
