<?php

declare(strict_types=1);

/**
 * Seed the Folio demo with a few Pages and Articles so the admin isn't empty.
 *
 * Idempotent: clears storage/data first, then recreates the records by POSTing
 * through the real kernel (same path the admin "New" form uses). No auth config
 * is present, so CsrfMiddleware is a no-op and no token is needed here.
 *
 * Run: php examples/folio-demo/seed.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use Preflow\Core\Application;

$base = __DIR__;

// Reset storage so re-seeding is deterministic.
$dataDir = $base . '/storage/data';
if (is_dir($dataDir)) {
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dataDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($rii as $file) {
        if ($file->getFilename() === '.gitkeep') {
            continue; // keep the tracked placeholder so the dir survives in git
        }
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
}

$app = Application::create($base);
$app->boot();
$factory = new Psr17Factory();

$records = [
    ['page', ['title' => 'Home', 'slug' => 'home', 'body' => '<p>Welcome to the site.</p>', 'status' => 'published']],
    ['page', ['title' => 'About Us', 'slug' => 'about', 'body' => '<p>Who we are and what we do.</p>', 'status' => 'published']],
    ['page', ['title' => 'Contact', 'slug' => 'contact', 'body' => '<p>How to reach us.</p>', 'status' => 'draft']],
    ['article', ['title' => 'Hello World', 'slug' => 'hello-world', 'body' => '<p>The first post.</p>', 'status' => 'published']],
    ['article', ['title' => 'On Warm Minimalism', 'slug' => 'warm-minimalism', 'body' => '<p>Notes on the design.</p>', 'status' => 'draft']],
];

foreach ($records as [$type, $data]) {
    $request = $factory->createServerRequest('POST', '/folio/' . $type)
        ->withAttribute('type', $type)
        ->withParsedBody($data);
    $status = $app->handle($request)->getStatusCode();
    echo sprintf("  %-8s %-18s -> %d\n", $type, $data['slug'], $status);
}

echo "Seeded " . count($records) . " records into {$dataDir}\n";
