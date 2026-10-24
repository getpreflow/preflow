<?php

declare(strict_types=1);

namespace Preflow\Folio\Field;

/**
 * Marker for field types whose stored value is plain searchable text. Folio tells the data
 * package which types these are (DataManager::fieldKinds()), so loose search covers them.
 * Extension field types opt in by implementing it.
 */
interface SearchableText {}
