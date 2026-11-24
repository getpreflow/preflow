<?php

// Folio's media library (preflow/media). Written by `php preflow folio:install`; yours to change.
// Its records go into the app's default storage (config/data.php), where `php preflow migrate`
// creates their table. The files go to public/media, and deleted ones wait in storage/media-bin
// for a while.
return [
    'storage' => '__STORAGE__',
    'bin_dir' => __DIR__ . '/../storage/media-bin',
];
