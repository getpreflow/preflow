<?php

// Messages of preflow/validation's rules (scope public) in this language. The keys and the
// placeholders (:min, :max, :step, :values) are those of lang/en/validation.php.
return [
    'required' => 'Este campo é obrigatório.',
    'email' => 'Deve ser um endereço de e-mail válido.',
    'url' => 'Deve ser um URL válido.',
    'numeric' => 'Deve ser um número.',
    'integer' => 'Deve ser um número inteiro.',
    'min' => [
        'number' => 'Deve ser no mínimo :min.',
        'string' => 'Deve ter no mínimo :min caracteres.',
    ],
    'max' => [
        'number' => 'Deve ser no máximo :max.',
        'string' => 'Deve ter no máximo :max caracteres.',
    ],
    'between' => 'Deve estar entre :min e :max.',
    'step' => 'Deve ser um múltiplo de :step.',
    'in' => 'Deve ser um destes: :values.',
    'regex' => 'O formato é inválido.',
    'slug' => [
        'segment' => 'Apenas letras minúsculas a-z, dígitos e hífenes simples entre eles, p. ex. "sobre-nos".',
        'path' => 'Apenas letras minúsculas a-z, dígitos, hífenes simples e barras entre eles, p. ex. "docs/sobre-nos".',
    ],
    'confirmed' => 'A confirmação não corresponde.',
    'unique' => 'Este valor já está em uso.',
];
