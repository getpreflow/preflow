<?php

// Messages of preflow/validation's rules (scope public) in this language. The keys and the
// placeholders (:min, :max, :step, :values) are those of lang/en/validation.php.
return [
    'required' => 'Bu alan zorunludur.',
    'email' => 'Geçerli bir e-posta adresi olmalıdır.',
    'url' => 'Geçerli bir URL olmalıdır.',
    'numeric' => 'Bir sayı olmalıdır.',
    'integer' => 'Bir tam sayı olmalıdır.',
    'min' => [
        'number' => 'En az :min olmalıdır.',
        'string' => 'En az :min karakter olmalıdır.',
    ],
    'max' => [
        'number' => 'En fazla :max olmalıdır.',
        'string' => 'En fazla :max karakter olmalıdır.',
    ],
    'between' => ':min ile :max arasında olmalıdır.',
    'step' => ':step değerinin katı olmalıdır.',
    'in' => 'Şunlardan biri olmalıdır: :values.',
    'regex' => 'Biçim geçersiz.',
    'slug' => [
        'segment' => 'Yalnızca a-z küçük harfler, rakamlar ve aralarında tek tireler kullanılabilir, ör. "hakkimizda".',
        'path' => 'Yalnızca a-z küçük harfler, rakamlar, aralarında tek tireler ve eğik çizgiler kullanılabilir, ör. "docs/hakkimizda".',
    ],
    'confirmed' => 'Onay eşleşmiyor.',
    'unique' => 'Bu değer zaten kullanılıyor.',
];
