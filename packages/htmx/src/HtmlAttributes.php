<?php

declare(strict_types=1);

namespace Preflow\Htmx;

final readonly class HtmlAttributes implements \Stringable
{
    /**
     * @param array<string, string> $attributes
     */
    public function __construct(
        private array $attributes = [],
    ) {}

    public function merge(self $other): self
    {
        return new self(array_merge($this->attributes, $other->attributes));
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->attributes;
    }

    public function __toString(): string
    {
        if ($this->attributes === []) {
            return '';
        }

        $parts = [];
        foreach ($this->attributes as $name => $value) {
            $escaped = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $parts[] = "{$name}=\"{$escaped}\"";
        }

        return implode(' ', $parts);
    }
}
