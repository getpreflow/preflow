<?php

// architecture.no-ambient-state fixture: one hit of the superglobal rule.
function ambientStateSuperglobal(): string
{
    return (string) ($_SERVER['REQUEST_URI'] ?? '/');
}
