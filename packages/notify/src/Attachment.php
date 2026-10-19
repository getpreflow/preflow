<?php

declare(strict_types=1);

namespace Preflow\Notify;

final readonly class Attachment
{
    private function __construct(
        public ?string $path,
        public ?string $contents,
        public string $name,
        public ?string $mime,
    ) {}

    public static function fromPath(string $path, ?string $name = null, ?string $mime = null): self
    {
        return new self($path, null, $name ?? basename($path), $mime);
    }

    public static function fromContents(string $contents, string $name, ?string $mime = null): self
    {
        return new self(null, $contents, $name, $mime);
    }
}
