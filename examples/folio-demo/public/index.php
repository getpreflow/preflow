<?php

declare(strict_types=1);

// Demo app: boots off the monorepo's root vendor (no separate composer install).
// dirname(__DIR__, 3) = repo root (examples/folio-demo/public -> examples/folio-demo -> examples -> root).
require dirname(__DIR__, 3) . '/vendor/autoload.php';

$app = Preflow\Core\Application::create(dirname(__DIR__));
$app->boot();
$app->run();
