<?php

declare(strict_types=1);

namespace Preflow\Folio\Translations;

use Preflow\Core\Cache\CacheCleared;
use Preflow\Core\Cache\CacheSource;
use Preflow\Core\Cache\CacheStats;
use Preflow\Folio\FolioText;

/**
 * The admin overrides compiled per locale: storage/cache/folio-translations-{locale}.php, a
 * PHP file returning key => text (so OPcache keeps it). Written on the first admin request
 * that asks for the locale, removed by every save and by Settings → Cache (cache:clear). The
 * request that removes a file keeps what it reads again in memory only (its own flash message
 * asks for texts after the change); the next request writes the file.
 */
final class OverridesCache implements CacheSource
{
    public const string KEY = 'folio-translations';

    /** @var array<string, array<string, string>> locale => texts read or written in this request */
    private array $memo = [];

    /** @var array<string, true> locales whose file this request removed (not written again by it) */
    private array $removed = [];

    private bool $clearedAll = false;

    public function __construct(
        private readonly string $dir,
    ) {}

    /** A new admin request (AdminLocaleMiddleware): read the files again, write them again. */
    public function startRequest(): void
    {
        $this->memo = [];
        $this->removed = [];
        $this->clearedAll = false;
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return FolioText::get('folio.cache.translations');
    }

    /**
     * The compiled overrides of a locale, or null when there is no compiled file.
     *
     * @return array<string, string>|null
     */
    public function read(string $locale): ?array
    {
        if (isset($this->memo[$locale])) {
            return $this->memo[$locale];
        }
        $file = $this->file($locale);
        if (!is_file($file)) {
            return null;
        }
        $texts = @include $file;

        return is_array($texts) ? $this->memo[$locale] = array_filter($texts, 'is_string') : null;
    }

    /** @param array<string, string> $texts */
    public function write(string $locale, array $texts): void
    {
        $this->memo[$locale] = $texts;
        if ($this->clearedAll || isset($this->removed[$locale])) {
            return;
        }
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            return; // no cache: the next request reads the table again
        }
        $file = $this->file($locale);
        $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, "<?php\n\nreturn " . var_export($texts, true) . ";\n", LOCK_EX) === false || !@rename($tmp, $file)) {
            @unlink($tmp);

            return;
        }
        self::invalidate($file);
    }

    /** Removes the compiled file of a locale. */
    public function forget(string $locale): void
    {
        unset($this->memo[$locale]);
        $this->removed[$locale] = true;
        $file = $this->file($locale);
        if (is_file($file) && @unlink($file)) {
            self::invalidate($file);
        }
    }

    public function stats(): CacheStats
    {
        $bytes = 0;
        $files = $this->files();
        foreach ($files as $file) {
            $bytes += (int) @filesize($file);
        }

        return new CacheStats(count($files), $bytes);
    }

    public function clear(): CacheCleared
    {
        $this->memo = [];
        $this->clearedAll = true;
        $entries = 0;
        $bytes = 0;
        foreach ($this->files() as $file) {
            $size = (int) @filesize($file);
            if (@unlink($file)) {
                self::invalidate($file);
                $entries++;
                $bytes += $size;
            }
        }

        return new CacheCleared($entries, $bytes);
    }

    private function file(string $locale): string
    {
        return $this->dir . '/folio-translations-' . $locale . '.php';
    }

    /** @return list<string> */
    private function files(): array
    {
        return glob($this->dir . '/folio-translations-*.php') ?: [];
    }

    private static function invalidate(string $file): void
    {
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }
}
