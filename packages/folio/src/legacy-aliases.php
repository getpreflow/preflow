<?php

declare(strict_types=1);

/**
 * Old class names that keep working for one release after their class moved. Loaded by Composer
 * ("autoload.files"). Until 0.93: Preflow\Folio\Field\Types\OptionsFrom (0.92: use
 * Preflow\Data\Validation\OptionsFrom, whose second parameter is the source's label; the core
 * reads the source without Folio's field type). Gone in 0.72: the publishing names of 0.71 (use
 * Preflow\Publishing), the asset names of 0.34 (Preflow\Folio\Assets\*: use Preflow\Media) and the
 * record users of 0.38 (Preflow\Folio\Users\RecordUser and RecordUserProvider: use Preflow\Auth\Record).
 */

$aliases = [
    'Preflow\\Folio\\Field\\Types\\OptionsFrom' => \Preflow\Data\Validation\OptionsFrom::class,
];

foreach ($aliases as $old => $new) {
    if (!class_exists($old, false)) {
        class_alias($new, $old);
    }
}

unset($aliases, $old, $new);
