<?php

declare(strict_types=1);

namespace Preflow\Folio\Extension;

/**
 * Files an enabled extension ships in its assets/ folder, served without login at
 * {prefix}/_assets/x/{extension}/{path} (ExtensionAssetController) and linked with
 * content-hash versioned URLs, so responses cache immutably and still bust on edit.
 *
 * Only plain relative paths (segments of letters, digits, ".", "_" and "-", none starting
 * with "."; no "..", backslash, "%", NUL or empty segment) of an allowed type, whose real
 * path (symlinks resolved) is a file inside the extension's real assets/ folder.
 */
final class ExtensionAssets
{
    /** Served types by file extension (lower case only) => Content-Type. */
    public const TYPES = [
        'css' => 'text/css; charset=UTF-8',
        'js' => 'text/javascript; charset=UTF-8',
        'mjs' => 'text/javascript; charset=UTF-8',
        'map' => 'application/json; charset=UTF-8',
        'json' => 'application/json; charset=UTF-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'woff2' => 'font/woff2',
    ];

    private const NAME = '/^[a-z][a-z0-9_]*$/';
    private const PATH = '#^[A-Za-z0-9_-][A-Za-z0-9._-]*(?:/[A-Za-z0-9_-][A-Za-z0-9._-]*)*$#D';

    private readonly string $prefix;

    public function __construct(
        private readonly ExtensionRegistry $extensions,
        string $prefix,
    ) {
        $this->prefix = rtrim($prefix, '/');
    }

    /** The file's absolute real path, or null when it is not served. */
    public function path(string $extension, string $path): ?string
    {
        if (preg_match(self::NAME, $extension) !== 1 || !$this->extensions->isEnabled($extension)) {
            return null;
        }
        if (preg_match(self::PATH, $path) !== 1 || self::type($path) === null) {
            return null; // PATH: no segment starts with ".", so no "." or ".." segment
        }

        $base = realpath($this->extensions->get($extension)->folder . '/assets');
        if ($base === false || !is_dir($base)) {
            return null;
        }
        $real = realpath($base . '/' . $path);
        if ($real === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR) || !is_file($real) || self::type($real) === null) {
            return null; // missing, a folder, or a symlink leaving assets/ or naming another type
        }

        return $real;
    }

    /** {prefix}/_assets/x/{extension}/{path}?v={first 12 hex of the file's xxh3}; throws when the file is not served. */
    public function url(string $extension, string $path): string
    {
        $real = $this->path($extension, $path);
        if ($real === null) {
            throw new ExtensionException(sprintf(
                'Extension asset "%s" of extension "%s" is not served: it must be a %s file in the assets/ folder of an enabled extension.',
                $path,
                $extension,
                implode(', ', array_map(fn (string $t) => '.' . $t, array_keys(self::TYPES))),
            ));
        }

        return $this->base($extension) . $path . '?v=' . substr(hash_file('xxh3', $real), 0, 12);
    }

    /** {prefix}/_assets/x/{extension}/ */
    public function base(string $extension): string
    {
        return $this->prefix . '/_assets/x/' . $extension . '/';
    }

    /** The Content-Type of a served type, null for any other (the extension is matched case-sensitively). */
    public static function type(string $path): ?string
    {
        $dot = strrpos(basename($path), '.');

        return $dot === false ? null : (self::TYPES[substr(basename($path), $dot + 1)] ?? null);
    }
}
