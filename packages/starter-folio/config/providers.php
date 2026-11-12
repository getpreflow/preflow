<?php

return [
    \Preflow\Folio\FolioServiceProvider::class,
    // After Folio: rbac answers Folio's permission questions from the stored roles, and keeps the staff accounts.
    \Preflow\Rbac\RbacServiceProvider::class,
    \App\Providers\AppServiceProvider::class,
];
