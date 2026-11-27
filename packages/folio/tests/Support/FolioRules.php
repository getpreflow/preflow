<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Support;

use Preflow\Data\DataManager;
use Preflow\Folio\Field\CoreFieldRules;
use Preflow\Folio\Field\FieldTypeRegistry;

/**
 * Folio's field types' rules given to a hand-built DataManager, as FolioServiceProvider does at
 * boot (CoreFieldRules::register()): for tests that build a RecordValidator without an app. Once
 * per DataManager, however many controllers a test builds on it.
 */
final class FolioRules
{
    /** @var \WeakMap<DataManager, true>|null */
    private static ?\WeakMap $registered = null;

    public static function register(FieldTypeRegistry $fieldTypes, DataManager $dm): void
    {
        self::$registered ??= new \WeakMap();
        if (isset(self::$registered[$dm])) {
            return;
        }
        self::$registered[$dm] = true;
        CoreFieldRules::register($fieldTypes, $dm->fieldKinds(), $dm->recordRules());
    }
}
