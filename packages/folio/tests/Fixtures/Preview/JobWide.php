<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Preview;

use Preflow\Components\Component;

/** A second view's component of the type preview tests: the record as a wide row. */
final class JobWide extends Component
{
    public string $title = '';
    public string $view = '';

    public function resolveState(): void
    {
        $props = $this->getProps();
        $this->title = (string) (((array) ($props['record'] ?? []))['title'] ?? '');
        $this->view = (string) ($props['view'] ?? '');
    }
}
