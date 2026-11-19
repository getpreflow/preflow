<?php

// architecture.no-ambient-state fixture: one hit of the context-dump rule.
function ambientStateContextDump(): string
{
    return var_export(\Preflow\Core\Context\Contexts::current(), true);
}
