<?php

declare(strict_types=1);

// Demo app: boots off the monorepo's root vendor (no separate composer install).
// dirname(__DIR__, 3) = repo root (examples/folio-demo/public -> examples/folio-demo -> examples -> root).
// Under `php -S … -t examples/folio-demo/public`, let the dev server send real files
// (library media under /media) itself, as a production web server would.
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH))) {
    return false;
}

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$app = Preflow\Core\Application::create(dirname(__DIR__));
$app->boot();
$app->run();
