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
use Preflow\Folio\Assets\AssetLibrary;
use Preflow\Folio\Publishing\Publisher;

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

// Library files are runtime data too: start from an empty media directory.
$mediaDir = $base . '/public/media';
if (is_dir($mediaDir)) {
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($mediaDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($rii as $file) {
        if ($file->getFilename() !== '.gitkeep') {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
    }
}

$app = Application::create($base);
$app->boot();
$dm = $app->container()->get(DataManager::class);
$registry = $app->container()->get(TypeRegistry::class);
$publisher = $app->container()->get(Publisher::class);

// A sample hero image, drawn with GD (no binary in the repo) and imported into the asset library.
$heroImage = '';
if (function_exists('imagecreatetruecolor')) {
    $img = imagecreatetruecolor(1920, 820);
    for ($y = 0; $y < 820; $y++) {
        $c = imagecolorallocate($img, 20 + (int) ($y / 12), 60 + (int) ($y / 9), 120 + (int) ($y / 8));
        imageline($img, 0, $y, 1919, $y, (int) $c);
    }
    imagefilledellipse($img, 1440, 300, 260, 260, (int) imagecolorallocate($img, 250, 214, 120));
    $tmp = tempnam(sys_get_temp_dir(), 'folio-demo') . '.png';
    imagepng($img, $tmp);
    $library = $app->container()->get(AssetLibrary::class);
    $asset = $library->importFile($tmp, 'demo-hero.png', 'seed');
    $library->update($asset->id, ['alt' => ['en' => 'An evening sky over a calm sea', 'de' => 'Ein Abendhimmel über ruhiger See'], 'focal_x' => 75, 'focal_y' => 35]);
    unlink($tmp);
    $heroImage = $asset->id;
}

$records = [
    ['page', ['title' => 'Home', 'slug' => 'home', 'body' => '<p>Welcome to the site.</p>',
        'sections' => [[
            '_uid' => 'a1b2c3d4e5f60718', '_block' => 'hero',
            'headline' => 'Pages built from blocks', 'intro' => 'Each section is a Preflow component; edit it in Folio.', 'image' => $heroImage,
            'links' => [
                ['_uid' => '0f1e2d3c4b5a6978', '_block' => 'link', 'label' => 'About us', 'url' => '/about'],
            ],
        ]],
    ]],
    // Translated: German title/slug/body, visible in both languages (/about and /de/ueber-uns).
    ['page', [
        'title' => ['en' => 'About Us', 'de' => 'Über uns'],
        'slug' => ['en' => 'about', 'de' => 'ueber-uns'],
        'body' => ['en' => '<p>Who we are and what we do.</p>', 'de' => '<p>Wer wir sind und was wir tun.</p>'],
        '_locales' => ['en', 'de'],
    ]],
    // Never published: a draft that only the admin (and preview) can see.
    ['page', ['title' => 'Contact', 'slug' => 'contact', 'body' => '<p>How to reach us.</p>', '_publish' => false]],
    ['article', ['title' => 'Hello World', 'slug' => 'hello-world', 'body' => '<p>The first post.</p>', 'status' => 'published']],
    ['article', ['title' => 'On Warm Minimalism', 'slug' => 'warm-minimalism', 'body' => '<p>Notes on the design.</p>', 'status' => 'draft']],
];

// Singleton: site settings (id = type key).
$records[] = ['site', ['uuid' => 'site', 'name' => 'Folio Demo', 'tagline' => ['en' => 'A Preflow CMS', 'de' => 'Ein Preflow-CMS']]];

foreach ($records as [$type, $data]) {
    $typeDef = $registry->get($type);
    $data[$typeDef->idField] ??= bin2hex(random_bytes(16));
    $publish = $data['_publish'] ?? true;
    unset($data['_publish']);
    $record = DynamicRecord::fromArray($typeDef, $data);
    $dm->saveType($record);
    if ($publish && $publisher->enabled($type)) {
        $publisher->publish($record, 'seed');
    }
    $slug = $data['slug'] ?? $data[$typeDef->idField];
    echo sprintf("  %-8s %-18s -> ok\n", $type, is_array($slug) ? $slug['en'] : $slug);
}

echo "Seeded " . count($records) . " records into {$dataDir}\n";
echo "Admin login: admin@folio.test / " . (getenv('FOLIO_ADMIN_PASSWORD') ?: 'folio-admin') . " (set FOLIO_ADMIN_PASSWORD to override)\n";
