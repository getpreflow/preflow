<?php

declare(strict_types=1);

// The script's own classes (Preflow\Tools\Convert\*), outside any package and Composer.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Preflow\\Tools\\Convert\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
