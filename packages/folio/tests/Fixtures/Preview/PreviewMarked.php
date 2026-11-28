<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Preview;

use Preflow\Components\Component;

/** A type preview's view component that links a record with preview_mark() (the marks' language, TypePreviewTest). */
final class PreviewMarked extends Component
{
    public function resolveState(): void {}
}
