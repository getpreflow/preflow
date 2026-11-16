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
     * @param list<string> $forgetInput    path prefixes (whole segments) whose posted fields are never kept as old
     *                                     input when the session expired: a form that promises to store nothing
     *                                     (since 0.78)
     * @param list<string> $forgetFields   field names never kept as old input, on any form (since 0.78; besides
     *                                     the CSRF token and every field whose name contains "password")
     */
    public function __construct(
        public array $exempt = [self::COMPONENT_PREFIX],
        public array $trustedOrigins = [],
        public array $signInPaths = [],
        public array $forgetInput = [],
        public array $forgetFields = [],
    ) {}

    /** Whether a POST to $path keeps no old input (forgetInput). @since 0.78 */
    public function forgetsInputOf(string $path): bool
    {
        foreach ($this->forgetInput as $prefix) {
            $prefix = rtrim($prefix, '/');
            if ($prefix === '' || $path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            return new self();
        }

        return self::fromArray((array) require $path);
    }

    /** @param array<string, mixed> $config config/csrf.php's array */
    public static function fromArray(array $config): self
    {
        $strings = static fn (mixed $value): array => array_values(array_map('strval', (array) $value));

        return new self(
            exempt: array_values(array_unique([self::COMPONENT_PREFIX, ...$strings($config['exempt'] ?? [])])),
            trustedOrigins: $strings($config['trusted_origins'] ?? []),
            signInPaths: $strings($config['sign_in_paths'] ?? []),
            forgetInput: $strings($config['forget_input'] ?? []),
            forgetFields: $strings($config['forget_fields'] ?? []),
        );
    }
}
