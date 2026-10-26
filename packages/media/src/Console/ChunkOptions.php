<?php

declare(strict_types=1);

namespace Preflow\Media\Console;

/**
 * --id=<id>, --limit=<n> and --offset=<n> for the media commands. A limit lets a long
 * backfill run in chunks (a host that ends long SSH sessions): each run does at most n
 * assets and says how many are left.
 */
trait ChunkOptions
{
    /** @param list<string> $args the value of --name=..., null without it */
    private static function option(array $args, string $name): ?string
    {
        $value = null;
        foreach ($args as $arg) {
            if (str_starts_with($arg, "--{$name}=")) {
                $value = substr($arg, strlen($name) + 3);
            }
        }

        return $value;
    }

    /**
     * A whole-number option of at least $min; null without it.
     *
     * @param list<string> $args
     * @throws \InvalidArgumentException for anything else (the message is for the user)
     */
    private static function number(array $args, string $name, int $min): ?int
    {
        $value = self::option($args, $name);
        if ($value === null) {
            return null;
        }
        if (preg_match('/^\d+$/', $value) !== 1 || (int) $value < $min) {
            throw new \InvalidArgumentException("--{$name} needs a " . ($min > 0 ? 'positive' : 'non-negative') . " number.\n");
        }

        return (int) $value;
    }

    private static function plural(int $n, string $word): string
    {
        return "{$n} {$word}" . ($n === 1 ? '' : 's');
    }
}
