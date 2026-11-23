<?php

return match (getenv('APP_ENV') ?: 'prod') {
    'dev' => [\App\Providers\AppServiceProvider::class],
    default => [\App\Providers\AppServiceProvider::class],
};
