<?php

// architecture.no-ambient-state fixture: one hit of the frame-context-write rule.
function ambientStateFrameContextWrite(\Preflow\Core\Context\RenderContext $ctx): void
{
    \Preflow\Core\Context\Contexts::frame()->context = $ctx;
}
