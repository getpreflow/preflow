<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Fixtures\ModelSchema;

use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Index;
use Preflow\Data\Model;

#[Index('state', 'updated_at')]
#[Index('company', 'state')]
#[Entity(table: 'jobs', storage: 'sqlite', type: 'indexed_job')]
final class IndexedJob extends Model
{
    #[Field(index: true)]
    public ?string $company = null;

    #[Field]
    public ?string $state = null;

    #[Field(type: 'date')]
    public ?string $updated_at = null;
}
