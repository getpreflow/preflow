<?php

declare(strict_types=1);

namespace Preflow\Publishing;

use Preflow\Data\DataManager;
use Preflow\Data\Model;
use Preflow\Data\ModelMetadata;
use Preflow\Data\QueryBuilder;

/**
 * What the public site sees of a class-backed type, as objects of its class: the published
 * copies ({table}__published) that Publisher writes, or the records themselves for a type
 * without publishing (as LiveContent reads them). DataManager::find() and query() on the
 * class read the working copy, which staff may have changed since; this reads the snapshot.
 * In the admin preview and share previews it reads the working copies instead, as LiveContent
 * does for every render, so a page built on it previews the drafts like one built on a JSON type.
 * The objects are read-only either way (DataManager::findAs()): app code never writes through here.
 */
final class PublishedModels
{
    public function __construct(
        private readonly DataManager $dm,
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
     * The table LiveContent would read the class's type from: the published copies, or its own
     * table in a preview and for a type without publishing (it has no published copies, so the
     * record is what the site shows). Refuses a class that is not a type.
     */
    private function table(string $modelClass): string
    {
        $type = ModelMetadata::for($modelClass)->type;
        $registry = $this->dm->getTypeRegistry();
        if ($type === null || $registry === null || $registry->classFor($type) !== $modelClass) {
            throw new \LogicException("{$modelClass} is not a type: mark it with #[Entity(type: …)] under data.model_paths to read its published copies.");
        }

        $def = $registry->get($type);

        return $this->live->readsWorkingCopies($type) ? $def->table : ShadowTypes::published($def)->table;
    }
}
