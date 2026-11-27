<?php

declare(strict_types=1);

namespace App\Models;

use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Model;
use Preflow\Validation\Attributes\Validate;

/** A company that posts jobs. `type: 'company'` makes it a data type (the JSON API serves it: ContentController). */
#[Entity(table: 'companies', storage: 'default', type: 'company')]
final class Company extends Model
{
    #[Id]
    public ?string $uuid = null;

    #[Field(searchable: true)]
    #[Validate('required', 'max:80')]
    public ?string $name = null;

    #[Field(unique: true, length: 100)]
    public ?string $slug = null;

    #[Field]
    public ?string $place = null;

    #[Field]
    #[Validate('nullable', 'url')]
    public ?string $website = null;

    /**
     * One text per language once the site has more than one (config/i18n.php): then it holds a
     * map such as {"en": …, "de": …}, and a page shows one of them. With one language it is text.
     */
    #[Field(type: 'string', translatable: true)]
    #[Validate('nullable', 'max:300')]
    public array|string|null $summary = null;
}
