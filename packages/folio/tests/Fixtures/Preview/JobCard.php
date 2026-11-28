<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Preview;

use Preflow\Components\Component;

/**
 * A type preview's view component (TypePreviewTest): the props a preview passes, the post it links to
 * as the render reads it (folio_find, so a working copy inside the preview), and the site's side
 * effects (page_side_effects: a write, a mail, a cookie) that the preview must not let out.
 */
final class JobCard extends Component
{
    /** @var array<string, mixed> */
    public array $record = [];
    public string $type = '';
    public string $view = '';
    public string $locale = '';
    public string $flag = '';

    public function resolveState(): void
    {
        $props = $this->getProps();
        $this->record = (array) ($props['record'] ?? []);
        $this->type = (string) ($props['type'] ?? '');
        $this->view = (string) ($props['view'] ?? '');
        $this->locale = (string) ($props['locale'] ?? '');
        $this->flag = ($props['preview'] ?? null) === true ? 'yes' : 'no';
    }
}
