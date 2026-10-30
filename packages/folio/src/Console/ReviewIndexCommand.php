<?php

declare(strict_types=1);

namespace Preflow\Folio\Console;

use Preflow\Data\DataManager;
use Preflow\Data\TypeDefinition;
use Preflow\DevTools\Command\CommandInterface;
use Preflow\Folio\Content\ReviewIndexUpdater;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\ReportsTranslatableTexts;
use Preflow\I18n\Machine\ReviewIndex;

/**
 * preflow folio:review-index: rebuilds the index of records with unchecked machine translations
 * from the records themselves, e.g. after an import or project code wrote records past Folio.
 * Reads every record of every type with translatable texts; types without them are skipped.
 */
final class ReviewIndexCommand implements CommandInterface
{
    use BootsApplication;

    public function getName(): string { return 'folio:review-index'; }
    public function getDescription(): string { return 'Rebuild the index of records with unchecked machine translations'; }

    public function execute(array $args): int
    {
        $c = $this->app()->container();
        $dm = $c->get(DataManager::class);
        $registry = $dm->getTypeRegistry() ?? throw new \LogicException('folio:review-index needs a TypeRegistry.');
        $fieldTypes = $c->get(FieldTypeRegistry::class);
        $updater = $c->get(ReviewIndexUpdater::class);
        $now = (new \DateTimeImmutable())->format(DATE_ATOM);

        $entries = [];
        foreach ($c->get(TypeCatalog::class)->all() as $listing) {
            $def = $registry->get($listing->key);
            if (!self::hasTranslatableTexts($def, $fieldTypes)) {
                continue;
            }
            foreach ($dm->queryType($listing->key)->get()->items() as $record) {
                $entry = $updater->entry($record, $now);
                if ($entry !== null) {
                    $entries[] = $entry;
                }
            }
        }
        $c->get(ReviewIndex::class)->replaceAll($entries);

        $count = count($entries);
        echo $count === 1 ? "1 record with unchecked machine translations.\n" : "{$count} records with unchecked machine translations.\n";

        return 0;
    }

    /** Translatable fields, or fields whose type reports texts of its own (blocks, flows). */
    private static function hasTranslatableTexts(TypeDefinition $def, FieldTypeRegistry $fieldTypes): bool
    {
        if (!$def->locales->isMultilingual()) {
            return false;
        }
        if ($def->translatableFields() !== []) {
            return true;
        }
        foreach ($def->fields as $field) {
            if ($fieldTypes->get($field->type) instanceof ReportsTranslatableTexts) {
                return true;
            }
        }

        return false;
    }
}
