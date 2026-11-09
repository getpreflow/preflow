<?php

declare(strict_types=1);

namespace App\Models;

use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Model;
use Preflow\Validation\Attributes\Validate;

/** A company that posts jobs. `type: 'company'` makes it a Folio type (config/models/company.json). */
#[Entity(table: 'companies', storage: 'default', type: 'company')]
final class Company extends Model
{
    #[Id]
    public ?string $uuid = null;

    #[Field(searchable: true)]
    #[Validate('required', 'max:80')]
    public ?string $name = null;

    #[Field(unique: true, length: 100)]
    #[Validate('required', 'slug')]
    public ?string $slug = null;

    #[Field]
    public ?string $place = null;

    #[Field]
    #[Validate('nullable', 'url')]
    public ?string $website = null;

    #[Field]
    #[Validate('nullable', 'max:300')]
    public ?string $summary = null;

    /** An asset id from Folio's media library. */
    #[Field(type: 'asset', label: 'Logo', config: ['asset' => ['multiple' => false, 'accept' => 'image/*']])]
    public ?string $logo = null;
}
