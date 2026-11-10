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
     * The value as written after "=". One well-formed quoted string, optionally followed by
     * whitespace and a # comment: "double quoted" (\" is a quote, # is text) or 'single quoted'
     * (literal). Otherwise the rule before 0.63.2: a value that starts and ends with the same
     * quote is the text between them ("{"a":1}", 'Steffen's site'); an unquoted value ends at " #".
     */
    private static function value(string $raw): string
    {
        $quote = $raw[0] ?? '';

        if ($quote === '"' && preg_match('/^"((?:[^"\\\\]|\\\\.)*)"\s*(?:#.*)?$/s', $raw, $m) === 1) {
            return str_replace('\\"', '"', $m[1]);
        }
        if ($quote === "'" && preg_match("/^'([^']*)'\\s*(?:#.*)?$/s", $raw, $m) === 1) {
            return $m[1];
        }
        if (($quote === '"' || $quote === "'") && strlen($raw) >= 2 && str_ends_with($raw, $quote)) {
            return substr($raw, 1, -1);
        }

        $commentPos = strpos($raw, ' #');

        return $commentPos !== false ? trim(substr($raw, 0, $commentPos)) : $raw;
    }
}
