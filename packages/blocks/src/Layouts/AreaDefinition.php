<?php

declare(strict_types=1);

namespace Preflow\Blocks\Layouts;

use Preflow\Data\TypeFieldDefinition;

/**
 * One area of a layout. $width is set by layouts that divide a row (a column layout: its share, 1–12).
 * $labelParams fill the label's :name placeholders when it is a translation key (a column layout's
 * "block-library.columns.column" with ['n' => 2]: "Column 2"); an editor translates the label with them.
 *
 * @since 0.92.0
 */
final readonly class AreaDefinition
{
    /**
     * @param list<string> $allowed block keys; empty: every block
     * @param list<string> $except  block keys left out
     * @param array<string, string|int> $labelParams the label's parameters (since 0.92.0, with the class)
     */
    public function __construct(
        public string $name,
        public string $label,
        public array $allowed = [],
        public array $except = [],
        public ?int $min = null,
        public ?int $max = null,
        public ?string $help = null,
        public ?int $width = null,
        public array $labelParams = [],
    ) {}

    public function accepts(string $blockKey): bool
    {
        if (in_array($blockKey, $this->except, true)) {
            return false;
        }

        return $this->allowed === [] || in_array($blockKey, $this->allowed, true);
    }

    /**
     * The area as a blocks field (config "allowed", "min", "max"). "except" is not part of it: the
     * caller resolves it against the block registry into an allowed list.
     */
    public function blocksField(): TypeFieldDefinition
    {
        $config = [];
        if ($this->allowed !== []) {
            $config['allowed'] = $this->allowed;
        }
        if ($this->min !== null) {
            $config['min'] = $this->min;
        }
        if ($this->max !== null) {
            $config['max'] = $this->max;
        }

        return new TypeFieldDefinition(name: $this->name, type: 'blocks', label: $this->label, help: $this->help, config: $config);
    }
}
