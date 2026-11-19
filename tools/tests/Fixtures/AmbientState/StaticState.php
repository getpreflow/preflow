<?php

// architecture.no-ambient-state fixture: one hit of the static-state rule.
final class AmbientStateStaticState
{
    private static ?string $current = null;
}
