<?php

declare(strict_types=1);

namespace App\Components\ViewToggle;

use Preflow\Components\Component;

/** Cards or list, as a radio pair named `view`, so it is part of the search form. Prop: `view`. */
final class ViewToggle extends Component
{
    protected string $tag = 'fieldset';
    protected string $cssClass = 'view-toggle';
    protected bool $scopeCss = true;

    public string $view = 'cards';

    public function resolveState(): void
    {
        $this->view = ($this->props['view'] ?? 'cards') === 'list' ? 'list' : 'cards';
    }
}
