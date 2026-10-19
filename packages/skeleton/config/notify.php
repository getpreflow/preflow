<?php

// Outbound messages (preflow/notify). Credentials live in .env, never here.
// For real mail: composer require symfony/mailer, then add
//     'smtp' => ['driver' => 'symfony-mailer', 'dsn' => getenv('MAILER_DSN') ?: ''],
// and point channels.email.transport at it.
return [
    'transports' => [
        'log' => ['driver' => 'log'],
    ],
    'channels' => [
        'email' => ['transport' => 'log', 'from' => getenv('MAIL_FROM') ?: null],
        'sms' => ['transport' => 'log'],
    ],
];
