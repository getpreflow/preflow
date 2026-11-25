<?php

// Messages of preflow/validation's rules (scope public) in this language. The keys and the
// placeholders (:min, :max, :step, :values) are those of lang/en/validation.php.
return [
    'required' => '此项为必填项。',
    'email' => '必须是有效的电子邮件地址。',
    'url' => '必须是有效的网址。',
    'numeric' => '必须是数字。',
    'integer' => '必须是整数。',
    'min' => [
        'number' => '不能小于 :min。',
        'string' => '长度不能少于 :min 个字符。',
    ],
    'max' => [
        'number' => '不能大于 :max。',
        'string' => '长度不能超过 :max 个字符。',
    ],
    'between' => '必须介于 :min 和 :max 之间。',
    'step' => '必须是 :step 的倍数。',
    'in' => '必须是以下值之一：:values。',
    'regex' => '格式不正确。',
    'slug' => [
        'segment' => '只能包含小写字母（a–z）、数字和连字符（-），连字符只能出现在字符之间且不能连续，例如“about-us”。',
        'path' => '只能包含小写字母（a–z）、数字、连字符（-）和斜杠（/），连字符和斜杠只能出现在字符之间且不能连续，例如“docs/about-us”。',
    ],
    'confirmed' => '两次输入不一致。',
    'unique' => '该值已被占用。',
];
