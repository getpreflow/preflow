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
    'step' => ':step sayısının katı olmalıdır.',
    'in' => 'Şunlardan biri olmalıdır: :values.',
    'regex' => 'Biçim geçersiz.',
    'slug' => [
        'segment' => 'Yalnızca küçük harf (a–z), rakam ve bunların arasında tek tire kullanılabilir, ör. “biz-kimiz”.',
        'path' => 'Yalnızca küçük harf (a–z), rakam ile bunların arasında tek tire ve eğik çizgi kullanılabilir, ör. “docs/biz-kimiz”.',
    ],
    'confirmed' => 'Onay eşleşmiyor.',
    'unique' => 'Bu değer zaten kullanılıyor.',
    'phone' => 'Bir telefon numarası olmalıdır: rakamlar, isteğe bağlı olarak başta + ve boşluklar, örn. +41 44 123 45 67.',
    'color' => 'Onaltılı biçimde bir renk olmalıdır, örn. #1a2b3c (veya saydamlıklı #1a2b3c80).',
    'color_palette' => 'Sunulan renklerden birini seçin: :values.',
    'json' => 'Geçerli bir JSON olmalıdır, örn. {"ad": "değer"} veya [1, 2, 3].',
    'phone_region' => 'Bu numaranın hangi ülkeye ait olduğu anlaşılamıyor: numarayı + ve ülke koduyla başlatın, örn. +41 44 123 45 67.',
    'code_language' => 'Dil, php veya javascript gibi kısa bir ad olmalıdır (harfler, rakamlar ve + # . _ -).',
    'link' => [
        'kind' => 'Bağlantının neyi gösterdiğini seçin: :kinds.',
        'target' => 'Bağlantının neyi gösterdiğini seçin veya girin.',
        'type' => 'Bu bağlantı :refused türünde bir kayda işaret edemez; şunlardan birini seçin: :types.',
        'url' => 'https:// veya http:// ile başlayan bir web adresi ya da bu sitede / ile başlayan bir yol olmalıdır.',
    ],
];
