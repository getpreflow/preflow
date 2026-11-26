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
    'phone' => '電話番号を入力してください（数字。先頭の + と空白は可。例: +41 44 123 45 67）。',
    'color' => '16進数の色（例: #1a2b3c、透明度付きは #1a2b3c80）で入力してください。',
    'color_palette' => '用意された色の中から選んでください: :values。',
    'json' => '有効なJSONを入力してください（例: {"name": "value"} や [1, 2, 3]）。',
    'phone_region' => 'この番号の国を判別できません。+ と国番号から始めてください（例: +41 44 123 45 67）。',
    'link' => [
        'kind' => 'リンク先の種類を選んでください: :kinds。',
        'target' => 'リンク先を選ぶか入力してください。',
        'type' => 'このリンクは :refused のレコードを指すことはできません。次から選んでください: :types。',
        'url' => 'https:// または http:// で始まるウェブアドレス、または / で始まるこのサイトのパスを入力してください。',
    ],
];
