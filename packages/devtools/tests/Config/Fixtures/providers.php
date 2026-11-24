<?php

// The app's providers, in boot order.
return [ // first entry boots first
    /* Mail before the app. */
    \App\Providers\MailServiceProvider::class,
    \App\Providers\AppServiceProvider::class, // the app's own, last
];
