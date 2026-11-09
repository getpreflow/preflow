<?php

// Visitors who are not signed in get finished pages. The cache is off while APP_DEBUG=1
// (what .env.example sets); set APP_DEBUG=0 to see it work.
return [
    'enabled' => true,
];
