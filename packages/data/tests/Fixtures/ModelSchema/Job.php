<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Fixtures\ModelSchema;

use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Attributes\Relation;
use Preflow\Data\Model;
use Preflow\Validation\Attributes\Validate;

#[Entity(table: 'job', type: 'job')]
final class Job extends Model
{
    #[Id]
    public ?string $uuid = null;

    #[Field(label: 'Title')]
    #[Validate('required')]
    #[Validate('on:create', 'min:3')]
    public string $title = '';

    #[Field(type: 'select', config: ['options' => ['no' => 'No', 'hybrid' => 'Hybrid', 'fully' => 'Fully']])]
    public ?string $remote = null;

    #[Relation(to: 'company', labelField: 'name')]
    public ?string $company = null;

    #[Field(type: 'asset', config: ['accept' => ['image/*']])]
    public ?string $logo = null;

    #[Field]
    public ?int $salary_from = null;

    #[Field]
    public bool $open = false;
}
