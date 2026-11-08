<?php

declare(strict_types=1);

namespace Preflow\Core\Boot;

use Preflow\Core\Application;

/**
 * The compiled boot manifest as one boot sees it (`preflow optimize` writes it to
 * {basePath}/bootstrap/cache/boot.php, see ManifestFile). It holds what boot otherwise reads
 * and scans on every request although it only changes on deploy: the config files' results,
 * the package discovery data and the catalogs packages register (components, blocks,
 * extensions, models, ...).
 *
 * A boot sees it in one of three modes:
 * - off: no manifest (the console, debug mode, none written, or a runtime-checked source
 *   changed). Every value is computed live, as without a manifest.
 * - loaded: the values come from the manifest; anything it doesn't hold is computed live.
 * - recording: ManifestBuilder boots the app to write one; values are computed live and
 *   remembered for the manifest.
 *
 * A producer wraps its expensive, deterministic work in remember(): the result must depend
 * only on files that change on deploy (code, config files, model files) and the .env, never on
 * stored data or the request. Values must be exportable (scalars, arrays, enums and objects
 * with __set_state); anything else is simply not frozen and stays live.
 */
final class BootManifest
{
    private const int OFF = 0;
    private const int LOADED = 1;
    private const int RECORDING = 2;

    /** @var array<string, mixed> config file name => result (recording) */
    private array $config = [];

    /** @var array<string, mixed> key => value (recording) */
    private array $catalogs = [];

    /** @var list<array<string, mixed>>|null */
    private ?array $packages = null;

    /** @var array<string, mixed>|null */
    private ?array $routes = null;

    /** @var list<string> absolute paths whose signatures the manifest records (recording) */
    private array $sources = [];

    /** @var list<string> what could not be frozen (recording), for the optimize report */
    private array $skipped = [];

    /** @var list<\Closure(Application): void> */
    private array $warmers = [];

    /** @param array<string, mixed> $data the manifest file's array (loaded) */
    private function __construct(
        private readonly int $mode,
        private readonly array $data = [],
    ) {}

    /** No manifest: everything is computed live. */
    public static function off(): self
    {
        return new self(self::OFF);
    }

    /** Values computed live and remembered for the manifest (ManifestBuilder). */
    public static function recording(): self
    {
        return new self(self::RECORDING);
    }

    /** @param array<string, mixed> $data a manifest file's array, already checked by ManifestFile::load() */
    public static function fromData(array $data): self
    {
        return new self(self::LOADED, $data);
    }

    /** Whether values come from a manifest file. */
    public function active(): bool
    {
        return $this->mode === self::LOADED;
    }

    /** Whether this boot builds a manifest (`preflow optimize`). */
    public function isRecording(): bool
    {
        return $this->mode === self::RECORDING;
    }

    /**
     * A config file's result (config/{name}.php): from the manifest when it holds it, else from
     * $load (and remembered while recording). Application::configFile() is the way to read one.
     *
     * @param \Closure(): mixed $load
     */
    public function config(string $name, \Closure $load): mixed
    {
        if ($this->mode === self::LOADED && is_array($this->data['config'] ?? null) && array_key_exists($name, $this->data['config'])) {
            return $this->data['config'][$name];
        }
        $value = $load();
        if ($this->mode === self::RECORDING) {
            if (self::exportable($value)) {
                $this->config[$name] = $value;
            } else {
                $this->skipped[] = "config/{$name}.php (holds closures or objects; read live)";
            }
        }

        return $value;
    }

    /**
     * A catalog: from the manifest when it holds $key, else from $compute (and remembered while
     * recording, with $sources: the files or directories it was made from, for `optimize --check`).
     *
     * @template T
     * @param \Closure(): T $compute
     * @param list<string>  $sources absolute paths
     * @return T
     */
    public function remember(string $key, \Closure $compute, array $sources = []): mixed
    {
        if ($this->mode === self::LOADED && is_array($this->data['catalogs'] ?? null) && array_key_exists($key, $this->data['catalogs'])) {
            return $this->data['catalogs'][$key];
        }
        $value = $compute();
        if ($this->mode === self::RECORDING) {
            if (self::exportable($value)) {
                $this->catalogs[$key] = $value;
                $this->addSources($sources);
            } else {
                $this->skipped[] = "catalog {$key} (not exportable; computed live)";
            }
        }

        return $value;
    }

    /**
     * The package discovery data (InstalledPackages::read() of vendor/composer/installed.json).
     *
     * @param \Closure(): list<array<string, mixed>> $read
     * @return list<array<string, mixed>>
     */
    public function packages(\Closure $read): array
    {
        if ($this->mode === self::LOADED && is_array($this->data['packages'] ?? null)) {
            return $this->data['packages'];
        }
        $packages = $read();
        if ($this->mode === self::RECORDING) {
            $this->packages = $packages;
        }

        return $packages;
    }

    /**
     * The compiled route data a manifest holds (filled by the routing producer), or null.
     *
     * @return array<string, mixed>|null
     */
    public function routes(): ?array
    {
        return $this->mode === self::LOADED && is_array($this->data['routes'] ?? null) ? $this->data['routes'] : null;
    }

    /**
     * Stores the compiled route data while recording (ignored otherwise).
     *
     * @param array<string, mixed> $routes
     */
    public function recordRoutes(array $routes): void
    {
        if ($this->mode !== self::RECORDING) {
            return;
        }
        if (!self::exportable($routes)) {
            $this->skipped[] = 'routes (not exportable; compiled live)';

            return;
        }
        $this->routes = $routes;
    }

    /**
     * Work to run once the app has booted while a manifest is built: resolving a lazy service
     * whose catalog should be in the manifest (one that public requests build on first use).
     *
     * @param \Closure(Application): void $warm
     */
    public function whenBuilding(\Closure $warm): void
    {
        if ($this->mode === self::RECORDING) {
            $this->warmers[] = $warm;
        }
    }

    /** Files or directories the manifest's values were made from (recording; for `optimize --check`). */
    public function addSources(array $paths): void
    {
        if ($this->mode !== self::RECORDING) {
            return;
        }
        foreach ($paths as $path) {
            if (is_string($path) && $path !== '' && !in_array($path, $this->sources, true)) {
                $this->sources[] = $path;
            }
        }
    }

    /** @internal ManifestBuilder: runs the warmers (they may add more). */
    public function warm(Application $app): void
    {
        for ($i = 0; $i < count($this->warmers); $i++) {
            ($this->warmers[$i])($app);
        }
    }

    /**
     * @internal ManifestBuilder
     *
     * @return array{config: array<string, mixed>, catalogs: array<string, mixed>, packages: list<array<string, mixed>>|null, routes: array<string, mixed>|null, sources: list<string>, skipped: list<string>}
     */
    public function recorded(): array
    {
        return [
            'config' => $this->config,
            'catalogs' => $this->catalogs,
            'packages' => $this->packages,
            'routes' => $this->routes,
            'sources' => $this->sources,
            'skipped' => array_values(array_unique($this->skipped)),
        ];
    }

    /** Whether var_export() writes $value as PHP that rebuilds it: scalars, arrays, enums, objects with __set_state. */
    public static function exportable(mixed $value): bool
    {
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return true;
        }
        if (is_float($value)) {
            return is_finite($value);
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (!self::exportable($item)) {
                    return false;
                }
            }

            return true;
        }
        if ($value instanceof \UnitEnum) {
            return true;
        }
        if (is_object($value) && !$value instanceof \Closure && method_exists($value, '__set_state')) {
            foreach ((array) $value as $item) {
                if (!self::exportable($item)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }
}
