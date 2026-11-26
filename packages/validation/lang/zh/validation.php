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
    'phone' => '必须是电话号码：数字，可在开头加 + 并包含空格，例如 +41 44 123 45 67。',
    'color' => '必须是十六进制颜色，例如 #1a2b3c（带透明度为 #1a2b3c80）。',
    'color_palette' => '请从提供的颜色中选择一个：:values。',
    'json' => '必须是有效的 JSON，例如 {"name": "value"} 或 [1, 2, 3]。',
    'phone_region' => '无法判断此号码属于哪个国家：请以 + 和国家区号开头，例如 +41 44 123 45 67。',
    'datetime' => '必须是日期和时间；在编辑器中例如 2026-03-29 14:30（以 UTC 存储为 2026-03-29T12:30:00Z）。',
    'datetime_gap' => ':value 在 :zone 不存在（时钟会拨快）。请选择其他时间。',
    'time' => '必须是包含小时和分钟的时间，例如 14:30。',
    'daterange' => '必须是包含开始和结束的日期范围，例如 :example。',
    'daterange_order' => '结束（:end）早于开始（:start）。请选择不早于 :start 的结束。',
    'link' => [
        'kind' => '请选择链接指向的内容：:kinds。',
        'target' => '请选择或输入链接指向的内容。',
        'type' => '此链接不能指向 :refused 类型的记录；请从以下类型中选择：:types。',
        'url' => '必须是以 https:// 或 http:// 开头的网址，或以 / 开头的本站路径。',
    ],
];
