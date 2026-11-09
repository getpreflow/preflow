<?php

return [
    \Preflow\Folio\FolioServiceProvider::class,
    // After Folio: roles answer Folio's permission questions from config/roles.php.
    \Preflow\Roles\RolesServiceProvider::class,
    \App\Providers\AppServiceProvider::class,
];
