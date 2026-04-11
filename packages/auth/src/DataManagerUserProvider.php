<?php

declare(strict_types=1);

namespace Preflow\Auth;

use Preflow\Data\DataManager;
use Preflow\Data\Model;

final class DataManagerUserProvider implements UserProviderInterface
{
    /**
     * @param class-string<Model&Authenticatable> $modelClass
     */
    public function __construct(
        private readonly DataManager $dataManager,
        private readonly string $modelClass,
    ) {}

    public function findById(string $id): ?Authenticatable
    {
        $model = $this->dataManager->find($this->modelClass, $id);
        return $model instanceof Authenticatable ? $model : null;
    }

    public function findByCredentials(array $credentials): ?Authenticatable
    {
        $email = $credentials['email'] ?? null;
        if ($email === null) {
            return null;
        }

        $result = $this->dataManager->query($this->modelClass)
            ->where('email', $email)
            ->first();

        return $result instanceof Authenticatable ? $result : null;
    }
}
