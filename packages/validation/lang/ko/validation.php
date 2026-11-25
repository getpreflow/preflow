<?php

// Messages of preflow/validation's rules (scope public) in this language. The keys and the
// placeholders (:min, :max, :step, :values) are those of lang/en/validation.php.
return [
    'required' => '필수 입력 항목입니다.',
    'email' => '올바른 이메일 주소여야 합니다.',
    'url' => '올바른 URL이어야 합니다.',
    'numeric' => '숫자여야 합니다.',
    'integer' => '정수여야 합니다.',
    'min' => [
        'number' => ':min 이상이어야 합니다.',
        'string' => ':min자 이상이어야 합니다.',
    ],
    'max' => [
        'number' => ':max 이하여야 합니다.',
        'string' => ':max자 이하여야 합니다.',
    ],
    'between' => ':min에서 :max 사이여야 합니다.',
    'step' => ':step의 배수여야 합니다.',
    'in' => '다음 중 하나여야 합니다: :values',
    'regex' => '형식이 올바르지 않습니다.',
    'slug' => [
        'segment' => '영문 소문자(a–z), 숫자, 하이픈(-)만 사용할 수 있으며, 하이픈은 문자 사이에 하나씩만 넣을 수 있습니다(예: “about-us”).',
        'path' => '영문 소문자(a–z), 숫자, 하이픈(-), 슬래시(/)만 사용할 수 있으며, 하이픈과 슬래시는 문자 사이에 하나씩만 넣을 수 있습니다(예: “docs/about-us”).',
    ],
    'confirmed' => '확인용 입력값이 일치하지 않습니다.',
    'unique' => '이미 사용 중인 값입니다.',
];
