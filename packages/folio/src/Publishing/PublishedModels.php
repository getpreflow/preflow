<?php

declare(strict_types=1);

namespace Preflow\Folio\Publishing;

use Preflow\Data\DataManager;
use Preflow\Data\Model;
use Preflow\Data\ModelMetadata;
use Preflow\Data\QueryBuilder;
use Preflow\Folio\Content\LiveContent;
use Preflow\Folio\Content\TypeCatalog;

/**
 * What the public site sees of a class-backed type, as objects of its class: the published
 * copies ({table}__published) that Publisher writes. DataManager::find() and query() on the
 * class read the working copy, which staff may have changed since; this reads the snapshot.
 * In the admin preview and share previews it reads the working copies instead, as LiveContent
 * does for every render, so a page built on it previews the drafts like one built on a JSON type.
 * The objects are read-only either way (DataManager::findAs()): app code never writes through here.
 */
final class PublishedModels
{
    public function __construct(
        private readonly DataManager $dm,
        private readonly TypeCatalog $catalog,
        private readonly LiveContent $live,
    ) {}

    /**
     * @template T of Model
     * @param class-string<T> $modelClass
     * @return T|null
     */
    public function find(string $modelClass, string|int $id): ?Model
    {
        return $this->dm->findAs($modelClass, $id, $this->table($modelClass));
    }

    /**
     * @template T of Model
     * @param class-string<T> $modelClass
     * @return QueryBuilder<T>
     */
    public function query(string $modelClass): QueryBuilder
    {
        return $this->dm->queryAs($modelClass, $this->table($modelClass));
    }

    /**
     * The published table of the class's type, or its own table while previews show working copies;
     * refuses a class whose type has no published copies.
     */
    private function table(string $modelClass): string
    {
        $type = ModelMetadata::for($modelClass)->type;
        $registry = $this->dm->getTypeRegistry();
        if ($type === null || $registry === null || $registry->classFor($type) !== $modelClass) {
            throw new \LogicException("{$modelClass} is not a type: mark it with #[Entity(type: …)] under data.model_paths to read its published copies.");
        }
        if (!($this->catalog->get($type)?->publishing ?? false)) {
            throw new \LogicException("Type \"{$type}\" ({$modelClass}) has no publishing, so it has no published copies: read it with DataManager::find() and query(), or turn on \"publishing\" in its admin file.");
        }

        $def = $registry->get($type);

        return $this->live->showsWorkingCopies() ? $def->table : ShadowTypes::published($def)->table;
    }
}
