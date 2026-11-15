<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Fixtures\ModelSchema;

use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Model;

#[Entity(table: 'api_clients', type: 'api_client')]
final class ApiClient extends Model
{
    #[Id]
    public string $uuid = '';

    #[Field]
    public string $name = '';

    #[Field(exact: true)]
    public string $apiKey = '';
}
