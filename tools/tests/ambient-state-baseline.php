<?php

declare(strict_types=1);

/**
 * The ratchet of architecture.no-ambient-state (see ArchitectureGuardsTest): every hit that exists
 * today and that the render-context release moves onto the context. File (relative to packages/) =>
 * the trimmed text of each hitting line, once per hit (a line that occurs twice is listed twice);
 * the comment names the rules it hits. A task that fixes a line removes it here; an entry that no
 * longer hits fails the guard. Nothing is added here: a new exception goes to ambient-state-allow.php
 * with its reason. Empty when the release is done.
 *
 * The skeleton and the starters (skeleton/app, starter/app, starter-folio/app) are scanned too: new
 * sites copy them.
 *
 * @return array<string, list<string>>
 */
return [];
