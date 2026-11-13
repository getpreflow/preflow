<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Support;

use Preflow\Data\DataManager;
use Preflow\Folio\Content\FrontendResolver;
use Preflow\Folio\Content\RecordValidator;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Extension\Customization\Customizations;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Publishing\FolioRecordChecks;
use Preflow\Folio\Publishing\FolioRecordTypes;
use Preflow\Folio\Publishing\FolioSaveHooks;
use Preflow\Publishing\NoSaveHooks;
use Preflow\Publishing\Publisher;
use Preflow\Publishing\RecordDeleter;
use Preflow\Publishing\RecordWriter;

/**
 * The RecordWriter and RecordDeleter for an AdminController built by hand, wired with Folio's
 * adapters as FolioServiceProvider wires the container's: the rules (RecordValidator), the type
 * customizations' hooks (none without Customizations) and the orderable listings; no review index
 * and no addresses. Spread them after the controller's FrontendResolver.
 */
final class RecordWriters
{
    /** @return array{0: RecordWriter, 1: RecordDeleter} */
    public static function for(
        DataManager $dm,
        TypeCatalog $catalog,
        FieldTypeRegistry $fields,
        FrontendResolver $frontend,
        ?Publisher $publisher = null,
        ?RecordValidator $validator = null,
        ?Customizations $customizations = null,
    ): array {
        $hooks = $customizations !== null ? new FolioSaveHooks($customizations) : new NoSaveHooks();
        $checks = new FolioRecordChecks($validator ?? new RecordValidator($fields, $dm, $frontend));

        return [
            new RecordWriter($dm, $publisher, $checks, $hooks, new FolioRecordTypes($catalog)),
            new RecordDeleter($dm, $publisher, $hooks),
        ];
    }
}
