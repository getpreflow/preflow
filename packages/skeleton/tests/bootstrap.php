<?php

declare(strict_types=1);

use Preflow\Testing\Fast\SetupSnapshots;

require dirname(__DIR__) . '/vendor/autoload.php';

// One id per PHPUnit run: this run's set-up snapshots (Tests\Support\StarterApp) belong to it,
// and the run that made the id removes them at the end.
SetupSnapshots::bootstrap();
