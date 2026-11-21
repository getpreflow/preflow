<?php

// architecture.no-ambient-state fixture: one hit of the peek rule.
function ambientStatePeek(): bool
{
    return \Preflow\Core\Context\Contexts::peek() !== null;
}
