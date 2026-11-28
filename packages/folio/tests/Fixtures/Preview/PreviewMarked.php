<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Preview;

use Preflow\Components\Component;

/** A type preview's view component that links a record with preview_mark() and shows blocks (the marks' language, TypePreviewTest). */
final class PreviewMarked extends Component
{
    /** @var array<string, mixed> */
    public array $record = [];

    public function resolveState(): void
    {
        $this->record = (array) ($this->getProps()['record'] ?? []);
    }
}
