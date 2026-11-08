<?php

declare(strict_types=1);

namespace Preflow\Components;

/**
 * A component that shows what its error boundary renders when it fails, without failing: a demo
 * of error boundaries, a style guide entry. ComponentRenderer renders the boundary's output for
 * previewFailure() (ErrorBoundary::preview(): its fallback(), or the dev panel or the hidden
 * placeholder, as for a real failure in the phase resolveState) in place of the component, and
 * neither resolves nor renders the component. No error is reported, so a page cache still
 * stores the page; a component that really fails is reported as always, and so is a
 * previewFailure() that throws (its boundary renders that error).
 */
interface PreviewsFailure
{
    /** The failure to preview (not thrown). */
    public function previewFailure(): \Throwable;
}
