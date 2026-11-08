<?php

declare(strict_types=1);

namespace Preflow\DevTools\Command;

use Preflow\Core\Boot\ManifestBuilder;

/**
 * `preflow optimize` writes the boot manifest (bootstrap/cache/boot.php): the merged config,
 * the package discovery data and the catalogs, so a web request with APP_DEBUG=0 reads them
 * instead of scanning. Run it on every deploy after `composer install` and migrations /
 * `folio:extensions:apply`, before the release goes live.
 *
 * `preflow optimize --check` lists the sources changed since (exit 1), or exits 0 when the
 * manifest is current; without a manifest it says so and exits 1.
 */
final class OptimizeCommand implements CommandInterface
{
    public function __construct(
        private readonly ?string $basePath = null,
    ) {}

    public function getName(): string { return 'optimize'; }
    public function getDescription(): string { return 'Write the boot manifest (bootstrap/cache/boot.php); --check lists drift'; }

    public function execute(array $args): int
    {
        foreach ($args as $arg) {
            if ($arg !== '--check') {
                echo "Unknown argument: {$arg} (optimize or optimize --check)\n";

                return 1;
            }
        }
        try {
            $builder = new ManifestBuilder($this->basePath ?? (getcwd() ?: '.'));

            return in_array('--check', $args, true) ? $this->check($builder) : $this->write($builder);
        } catch (\Throwable $e) {
            fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");

            return 1;
        }
    }

    private function write(ManifestBuilder $builder): int
    {
        $result = $builder->write();
        if (!$result['written']) {
            echo "No boot manifest written: {$result['reason']}\n";

            return 0;
        }
        echo "Boot manifest written: {$result['file']}\n";
        echo '  config: ' . count($result['config']) . ' file(s); catalogs: ' . implode(', ', $result['catalogs']) . "\n";
        foreach ($result['skipped'] as $skipped) {
            echo "  live: {$skipped}\n";
        }

        return 0;
    }

    private function check(ManifestBuilder $builder): int
    {
        $drift = $builder->drift();
        if ($drift === null) {
            echo "No boot manifest (run preflow optimize).\n";

            return 1;
        }
        if ($drift === []) {
            echo "Boot manifest is current.\n";

            return 0;
        }
        echo "Boot manifest is out of date (run preflow optimize):\n";
        foreach ($drift as $line) {
            echo "  {$line}\n";
        }

        return 1;
    }
}
