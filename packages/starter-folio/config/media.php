<?php

// Folio's media library (company logos, images in rich text). Its records go into the app's
// SQLite database like everything else; `preflow migrate` creates the table. The files go to
// public/media, and deleted ones wait in storage/media-bin for a while (both git-ignored).
return [
    'storage' => 'sqlite',
    'bin_dir' => __DIR__ . '/../storage/media-bin',
];
