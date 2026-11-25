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
        'number' => ':min以上にしてください。',
        'string' => ':min文字以上にしてください。',
    ],
    'max' => [
        'number' => ':max以下にしてください。',
        'string' => ':max文字以内にしてください。',
    ],
    'between' => ':minから:maxの間にしてください。',
    'step' => ':stepの倍数にしてください。',
    'in' => '次のいずれかを選んでください: :values。',
    'regex' => '形式が正しくありません。',
    'slug' => [
        'segment' => '小文字のa-z、数字、および間に入る単一のハイフンのみ使用できます(例: "about-us")。',
        'path' => '小文字のa-z、数字、および間に入る単一のハイフンとスラッシュのみ使用できます(例: "docs/about-us")。',
    ],
    'confirmed' => '確認の入力が一致しません。',
    'unique' => 'この値はすでに使われています。',
];
