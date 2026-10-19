<?php

declare(strict_types=1);

namespace Preflow\Folio\Block;

/**
 * A block field: same types and options as a JSON type field. "allowed",
 * "min" and "max" configure nested "blocks" fields; "config" carries any other
 * field option (e.g. select "options").
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class BlockField
{
    /**
     * @param list<string>         $validate
     * @param array<string, mixed> $config
     * @param list<string>         $allowed
     */
    public function __construct(
        public readonly string $type = 'string',
        public readonly ?string $label = null,
        public readonly ?string $help = null,
        public readonly bool $translatable = false,
        public readonly array $validate = [],
        public readonly array $config = [],
        public readonly array $allowed = [],
        public readonly ?int $min = null,
        public readonly ?int $max = null,
    ) {}

    /** @return array<string, mixed> */
    public function fieldConfig(): array
    {
        $config = $this->config;
        if ($this->allowed !== []) {
            $config['allowed'] = $this->allowed;
        }
        if ($this->min !== null) {
            $config['min'] = $this->min;
        }
        if ($this->max !== null) {
            $config['max'] = $this->max;
        }

        return $config;
    }
}
