<?php

// architecture.no-ambient-state fixture: one hit of the context-property rule.
final class AmbientStateContextProperty
{
    public function __construct(private readonly \Preflow\Core\Context\RenderContext $context)
    {
    }
}
