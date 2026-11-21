<?php

// architecture.no-ambient-state fixture: one hit of the setter rule.
function ambientStateSetter(object $translator): void
{
    $translator->setLocale('de');
}
