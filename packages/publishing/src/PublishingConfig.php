<?php

declare(strict_types=1);

namespace Preflow\Publishing;

use Preflow\Core\Application;

/**
 * config/publishing.php (a missing file means the defaults):
 *
 *   'revisions'           revisions kept per record, default 20; 0 or null keeps every one
 *   'complete_languages'  refuse to publish while a visible language lacks texts the default
 *                         language has (LanguageChecks), default false
 *   'preview_ttl'         how long a share-preview link works, in seconds, default 604800 (7 days)
 */
final readonly class PublishingConfig
{
    public const int DEFAULT_REVISIONS = 20;

    public const int DEFAULT_PREVIEW_TTL = 604800;

    public function __construct(
        public ?int $revisions = self::DEFAULT_REVISIONS,
        public bool $completeLanguages = false,
        public int $previewTtl = self::DEFAULT_PREVIEW_TTL,
    ) {}

    public static function fromApp(Application $app): self
    {
        $config = $app->configFile('publishing');

        return self::fromArray(is_array($config) ? $config : []);
    }

    /**
     * Checked when built: revisions a whole number (an int or a string of digits) of 0 or more,
     * or null; anything else (a negative number, a float, text, a bool) throws.
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            self::revisions($config),
            (bool) ($config['complete_languages'] ?? false),
            (int) ($config['preview_ttl'] ?? self::DEFAULT_PREVIEW_TTL),
        );
    }

    /** @param array<string, mixed> $config */
    private static function revisions(array $config): ?int
    {
        if (!array_key_exists('revisions', $config)) {
            return self::DEFAULT_REVISIONS;
        }
        $value = $config['revisions'];
        if ($value === null) {
            return null;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < 0) {
            throw new \InvalidArgumentException('publishing.revisions must be 0 (keep all) or more.');
        }

        return $value;
    }
}
