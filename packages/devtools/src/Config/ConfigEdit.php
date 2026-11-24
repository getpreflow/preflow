<?php

declare(strict_types=1);

namespace Preflow\DevTools\Config;

/**
 * What one ConfigFileEditor step did to one config file.
 *
 * - `written`: the file did not exist and was created;
 * - `changed`: the intended addition was made and checked;
 * - `already`: nothing to do (the file, key or value is there), the file is untouched;
 * - `refused`: the file could not be edited safely; it holds its original bytes, and `snippet`
 *   is the exact text to add by hand (`message` says where and why).
 *
 * @since 0.85.0
 */
final readonly class ConfigEdit
{
    public const string WRITTEN = 'written';
    public const string CHANGED = 'changed';
    public const string ALREADY = 'already';
    public const string REFUSED = 'refused';

    public function __construct(
        public string $status,
        public string $file,
        public string $message,
        public ?string $snippet = null,
    ) {}

    public function isRefused(): bool
    {
        return $this->status === self::REFUSED;
    }
}
