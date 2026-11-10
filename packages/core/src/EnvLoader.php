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

            $value = self::value($value);

            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }

    /**
     * The value as written after "=": "double quoted" (\" is a quote, # is text), 'single quoted'
     * (literal), or unquoted (" #" starts a comment). Text after a closing quote is ignored;
     * a quote that never closes leaves the value as written.
     */
    private static function value(string $raw): string
    {
        $quote = $raw[0] ?? '';

        if ($quote === '"') {
            if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"/s', $raw, $m) === 1) {
                return str_replace('\\"', '"', $m[1]);
            }
        } elseif ($quote === "'") {
            $end = strpos($raw, "'", 1);
            if ($end !== false) {
                return substr($raw, 1, $end - 1);
            }
        }

        $commentPos = strpos($raw, ' #');

        return $commentPos !== false ? trim(substr($raw, 0, $commentPos)) : $raw;
    }
}
