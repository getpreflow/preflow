<?php

return [
    'transports' => ['mail' => ['driver' => 'memory']],
    'channels' => ['email' => ['transport' => 'mail', 'from' => 'site@site.test']],
];
