<?php

declare(strict_types=1);

namespace Preflow\Routing\Redirect;

/** Redirect settings from `config/redirects.php`; a missing file means the defaults. */
final readonly class RedirectConfig
{
    /**
     * @param list<string> $ignore extra prefixes (or, starting with '.', extensions) kept out of the not-found list
     */
    public function __construct(
        public bool $recordNotFound = true,
        public int $notFoundLimit = 1000,
        public array $ignore = [],
    ) {}

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            return new self();
        }

        $config = (array) require $path;
        $limit = (int) ($config['not_found_limit'] ?? 1000);

        if ($limit < 1) {
            throw new \InvalidArgumentException("redirects.not_found_limit must be 1 or more, got {$limit}.");
        }

        return new self(
            recordNotFound: (bool) ($config['not_found'] ?? true),
            notFoundLimit: $limit,
            ignore: array_values(array_map('strval', (array) ($config['ignore'] ?? []))),
        );
    }
}
