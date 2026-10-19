<?php

declare(strict_types=1);

/**
 * Seed the Folio demo with a few Pages and Articles so the admin isn't empty.
 *
 * Idempotent: clears storage/data first, then writes the records directly via
 * DataManager. Seeds must bypass the admin HTTP layer — it is now gated behind
 * login and CSRF-protected (config/auth.php), so POSTing through the kernel
 * without a session/token would be rejected.
 *
 * Run: php examples/folio-demo/seed.php [base-dir]
 *
 * base-dir (optional) seeds a copy of the demo instead (used by the tests).
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Preflow\Core\Application;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;

$base = isset($argv[1]) ? rtrim($argv[1], '/') : __DIR__;

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
$dm = $app->container()->get(DataManager::class);
$registry = $app->container()->get(TypeRegistry::class);

$records = [
    ['page', ['title' => 'Home', 'slug' => 'home', 'body' => '<p>Welcome to the site.</p>', 'status' => 'published']],
    // Translated: German title/slug/body, visible in both languages (/about and /de/ueber-uns).
    ['page', [
        'title' => ['en' => 'About Us', 'de' => 'Über uns'],
        'slug' => ['en' => 'about', 'de' => 'ueber-uns'],
        'body' => ['en' => '<p>Who we are and what we do.</p>', 'de' => '<p>Wer wir sind und was wir tun.</p>'],
        'status' => 'published',
        '_locales' => ['en', 'de'],
    ]],
    ['page', ['title' => 'Contact', 'slug' => 'contact', 'body' => '<p>How to reach us.</p>', 'status' => 'draft']],
    ['article', ['title' => 'Hello World', 'slug' => 'hello-world', 'body' => '<p>The first post.</p>', 'status' => 'published']],
    ['article', ['title' => 'On Warm Minimalism', 'slug' => 'warm-minimalism', 'body' => '<p>Notes on the design.</p>', 'status' => 'draft']],
];

foreach ($records as [$type, $data]) {
    $typeDef = $registry->get($type);
    $data[$typeDef->idField] = bin2hex(random_bytes(16));
    $dm->saveType(DynamicRecord::fromArray($typeDef, $data));
    echo sprintf("  %-8s %-18s -> ok\n", $type, is_array($data['slug']) ? $data['slug']['en'] : $data['slug']);
}

echo "Seeded " . count($records) . " records into {$dataDir}\n";
echo "Admin login: admin@folio.test / " . (getenv('FOLIO_ADMIN_PASSWORD') ?: 'folio-admin') . " (set FOLIO_ADMIN_PASSWORD to override)\n";
