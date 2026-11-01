<?php

declare(strict_types=1);

namespace Preflow\Core\Http\Csrf;

/** CSRF settings from `config/csrf.php`; a missing file means the defaults. */
final readonly class CsrfConfig
{
    /** Component actions carry signed tokens of their own; always exempt. */
    public const COMPONENT_PREFIX = '/--component/';

    /**
     * @param list<string> $exempt         path prefixes without a check besides '/--component/' (for routes that can't carry #[CsrfExempt])
     * @param list<string> $trustedOrigins extra origins (https://host[:port]) allowed to post
     * @param list<string> $signInPaths    extra sign-in forms (packages register their own)
     */
    public function __construct(
        public array $exempt = [self::COMPONENT_PREFIX],
        public array $trustedOrigins = [],
        public array $signInPaths = [],
    ) {}

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            return new self();
        }

        $config = (array) require $path;
        $strings = static fn (mixed $value): array => array_values(array_map('strval', (array) $value));

        return new self(
            exempt: array_values(array_unique([self::COMPONENT_PREFIX, ...$strings($config['exempt'] ?? [])])),
            trustedOrigins: $strings($config['trusted_origins'] ?? []),
            signInPaths: $strings($config['sign_in_paths'] ?? []),
        );
    }
}
