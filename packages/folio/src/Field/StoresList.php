<?php

declare(strict_types=1);

namespace Preflow\Folio\Field;

/**
 * Marker for field types whose value is a list (the tags field). Folio tells the data package
 * which types these are (FieldKinds::list()), so a save writes the value as a list in a JSON
 * file, also when it was given as JSON text or a comma string; SQL storage keeps it as JSON text.
 * Extension field types opt in by implementing it.
 *
 * @since 0.78
 */
interface StoresList {}
