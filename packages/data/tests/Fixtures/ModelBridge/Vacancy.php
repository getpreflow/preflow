<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Fixtures\ModelBridge;

use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Attributes\Relation;
use Preflow\Data\Model;
use Preflow\Validation\Attributes\Validate;

/** A class-backed type with a translatable field and a relation-only property (ruling R3). */
#[Entity(table: 'vacancy', type: 'vacancy')]
final class Vacancy extends Model
{
    #[Id]
    public ?string $uuid = null;

    /** @var array<string, string>|string|null a locale map on a multilingual site */
    #[Field(type: 'string', translatable: true)]
    #[Validate('required')]
    public array|string|null $title = null;

    #[Relation(to: 'company', labelField: 'name')]
    #[Validate('required')]
    public ?string $company = null;

    #[Field]
    public ?int $salary_from = null;

    #[Field]
    public bool $open = false;

    #[Field(unique: true, length: 40)]
    public ?string $code = null;

    public string $scratch = '';
}
