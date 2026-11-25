<?php

// Messages of preflow/validation's rules (scope public) in this language. The keys and the
// placeholders (:min, :max, :step, :values) are those of lang/en/validation.php.
return [
    'required' => 'この項目は必須です。',
    'email' => '有効なメールアドレスを入力してください。',
    'url' => '有効なURLを入力してください。',
    'numeric' => '数値を入力してください。',
    'integer' => '整数を入力してください。',
    'min' => [
        'number' => ':min以上の値を入力してください。',
        'string' => ':min文字以上で入力してください。',
    ],
    'max' => [
        'number' => ':max以下の値を入力してください。',
        'string' => ':max文字以内で入力してください。',
    ],
    'between' => ':min～:maxの範囲で入力してください。',
    'step' => ':stepの倍数を入力してください。',
    'in' => '次のいずれかを指定してください：:values。',
    'regex' => '形式が正しくありません。',
    'slug' => [
        'segment' => '半角の英小文字（a～z）、数字、ハイフンのみ使用できます。ハイフンは先頭・末尾や連続しては使用できません（例：「about-us」）。',
        'path' => '半角の英小文字（a～z）、数字、ハイフン、スラッシュのみ使用できます。ハイフンとスラッシュは先頭・末尾や連続しては使用できません（例：「docs/about-us」）。',
    ],
    'confirmed' => '確認用の入力内容が一致しません。',
    'unique' => 'この値はすでに使われています。',
];
