<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = Preflow\Core\Application::create(__DIR__ . '/..');
$app->boot();
$app->run();
