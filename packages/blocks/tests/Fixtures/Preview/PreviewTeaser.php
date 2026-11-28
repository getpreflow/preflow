<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Fixtures\Preview;

use Preflow\Components\Component;

/** A preview view's component: prints the props a preview passes, with CSS and JS of its own. */
final class PreviewTeaser extends Component
{
    /** @var array<string, mixed> */
    public array $record = [];
    public string $type = '';
    public string $view = '';
    public string $locale = '';
    public string $flag = '';
    public string $propNames = '';

    public function resolveState(): void
    {
        $props = $this->getProps();
        $this->record = (array) ($props['record'] ?? []);
        $this->type = (string) ($props['type'] ?? '');
        $this->view = (string) ($props['view'] ?? '');
        $this->locale = (string) ($props['locale'] ?? '');
        $this->flag = ($props['preview'] ?? null) === true ? 'yes' : 'no';
        $names = array_keys($props);
        sort($names);
        $this->propNames = implode(',', $names);
    }
}
