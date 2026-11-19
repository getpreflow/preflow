<?php

// architecture.no-ambient-state fixture: one hit of the clock rule.
function ambientStateClock(): int
{
    return time();
}
