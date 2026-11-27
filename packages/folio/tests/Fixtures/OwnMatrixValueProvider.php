<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures;

use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Data\DataManager;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\Values\FieldValue;

/** A site's own value for "matrix", registered before Folio's provider boots. */
final class OwnMatrixValueProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
    }

    public function boot(Container $container): void
    {
        $container->get(DataManager::class)->fieldKinds()->value('matrix', new class implements FieldValue {
            public function toStored(mixed $input, TypeFieldDefinition $field): mixed
            {
                return $input;
            }

            public function toOutput(mixed $stored, TypeFieldDefinition $field): mixed
            {
                return $stored;
            }

            public function shape(TypeFieldDefinition $field): string
            {
                return 'raw';
            }
        });
    }
}
