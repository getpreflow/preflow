<?php

// architecture.no-ambient-state fixture: a clock read marked as a stamp is no hit.
function ambientStateStamp(): string
{
    return 'export-' . date('Ymd-His') . '.zip'; // clock: stamp
}
