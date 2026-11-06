<?php

declare(strict_types=1);

namespace Preflow\Testing\Fast;

/**
 * Which test process this is. paratest numbers its workers (TEST_TOKEN 1, 2, …): each worker gets
 * its own databases and directories, so workers never see each other's data. A plain phpunit run
 * has no number and uses the plain names.
 */
final class TestWorker
{
    /** The worker's number, or null when this is not a paratest worker. */
    public static function token(): ?int
    {
        $token = getenv('TEST_TOKEN');

        return is_string($token) && ctype_digit($token) ? (int) $token : null;
    }

    /** '' or '_3'. */
    public static function suffix(): string
    {
        $token = self::token();

        return $token === null ? '' : '_' . $token;
    }

    /** 'site_test' becomes 'site_test_3' in worker 3. */
    public static function name(string $base): string
    {
        return $base . self::suffix();
    }

    /** A temp directory for this worker (e.g. a Folio cache), created. */
    public static function dir(string $base): string
    {
        $dir = sys_get_temp_dir() . '/' . $base . self::suffix();
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        return $dir;
    }

    /**
     * Makes a worker's MySQL database on its first run. $user needs the rights on the numbered
     * names: GRANT ALL PRIVILEGES ON `site\_test\_%`.* TO 'user'@'localhost'.
     */
    public static function createMysqlDatabase(\PDO $server, string $name, string $user): void
    {
        try {
            $server->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (\PDOException $e) {
            $pattern = str_replace('_', '\\_', (string) preg_replace('/\d+$/', '', $name)) . '%';
            throw new \RuntimeException("Cannot create {$name} for a parallel run ({$e->getMessage()}). Once, as a database admin: GRANT ALL PRIVILEGES ON `{$pattern}`.* TO '{$user}'@'localhost';", 0, $e);
        }
    }
}
