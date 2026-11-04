<?php

declare(strict_types=1);

namespace Preflow\Routing\Redirect;

/**
 * Tells noise (scanner probes for WordPress, dotfiles and backups; icons and manifests browsers
 * ask for on their own) from addresses worth listing.
 */
final class NotFoundFilter
{
    private const PREFIXES = [
        '/wp-', '/wordpress', '/cgi-bin', '/xmlrpc', '/phpmyadmin', '/pma', '/vendor/',
        '/server-status', '/.well-known/', '/autodiscover', '/ecp/', '/owa/', '/boaform',
        '/actuator', '/hnap1', '/cdn-cgi/',
        // Its "-precomposed" and size variants too ("/apple-touch-icon-152x152.png").
        '/apple-touch-icon',
    ];

    /** Files browsers request by themselves, whether or not a page links to them. */
    private const BROWSER_FILES = ['/favicon.ico', '/browserconfig.xml', '/site.webmanifest', '/manifest.json'];

    private const EXTENSIONS = [
        'php', 'asp', 'aspx', 'jsp', 'cgi', 'env', 'ini', 'sql', 'bak', 'old', 'orig', 'swp',
        'zip', 'tar', 'gz', 'tgz', 'rar', '7z', 'log', 'yml', 'yaml', 'conf', 'config', 'map',
    ];

    /** @var list<string> */
    private array $prefixes;

    /** @var list<string> */
    private array $extensions;

    /** @param list<string> $ignore entries starting with '.' add extensions, others add prefixes */
    public function __construct(array $ignore = [])
    {
        $this->prefixes = self::PREFIXES;
        $this->extensions = self::EXTENSIONS;

        foreach ($ignore as $entry) {
            $entry = strtolower(trim((string) $entry));

            if ($entry === '') {
                continue;
            }

            if ($entry[0] === '.') {
                $this->extensions[] = substr($entry, 1);
            } else {
                $this->prefixes[] = $entry;
            }
        }
    }

    public function ignores(string $path): bool
    {
        $lower = strtolower(rawurldecode($path));

        if (in_array($lower, self::BROWSER_FILES, true)) {
            return true;
        }

        foreach ($this->prefixes as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return true;
            }
        }

        $segments = explode('/', $lower);

        foreach ($segments as $segment) {
            if ($segment !== '' && $segment[0] === '.') {
                return true;
            }
        }

        $dot = strrpos($last = end($segments), '.');

        return $dot !== false && in_array(substr($last, $dot + 1), $this->extensions, true);
    }
}
