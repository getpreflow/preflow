<?php

declare(strict_types=1);

namespace Preflow\Core;

final class EnvLoader
{
    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Skip comments and blank lines
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            // Must contain =
            $eqPos = strpos($trimmed, '=');
            if ($eqPos === false) {
                continue;
            }

            $key = trim(substr($trimmed, 0, $eqPos));
            $value = trim(substr($trimmed, $eqPos + 1));

            // Don't overwrite existing environment variables
            if (getenv($key) !== false) {
                continue;
            }

            // Strip quotes
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            } else {
                // Strip inline comments (only for unquoted values)
                $commentPos = strpos($value, ' #');
                if ($commentPos !== false) {
                    $value = trim(substr($value, 0, $commentPos));
                }
            }

            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }
}
