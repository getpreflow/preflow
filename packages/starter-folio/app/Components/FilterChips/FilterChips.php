<?php

declare(strict_types=1);

namespace App\Components\FilterChips;

use Preflow\Components\Component;

/**
 * One filter as a row of chips: radio inputs, so one value is chosen and "Any" clears it, and
 * the form sends it like any other field. Props: `name`, `legend`, `options` (the values),
 * `selected`, and `labels`, a translation key prefix for the values ('' shows them as they are).
 */
final class FilterChips extends Component
{
    protected string $tag = 'fieldset';
    protected string $cssClass = 'filter-chips';
    protected bool $scopeCss = true;

    public string $name = '';
    public string $legend = '';
    /** @var list<string> */
    public array $options = [];
    public ?string $selected = null;
    public string $labels = '';

    public function resolveState(): void
    {
        $this->name = (string) ($this->props['name'] ?? '');
        $this->legend = (string) ($this->props['legend'] ?? '');
        $this->options = array_values(array_map('strval', (array) ($this->props['options'] ?? [])));
        $selected = $this->props['selected'] ?? null;
        $this->selected = is_string($selected) && $selected !== '' ? $selected : null;
        $this->labels = (string) ($this->props['labels'] ?? '');
    }
}
