<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Models;

use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Model;
use Preflow\Validation\Attributes\Validate;

/** A class-backed type, edited in Folio through config/models/job.json. Knows nothing of Folio. */
#[Entity(table: 'job', storage: 'sqlite', type: 'job')]
final class Job extends Model
{
    #[Id]
    public ?string $uuid = null;

    #[Field]
    #[Validate('required')]
    public string $title = '';

    #[Field]
    public ?string $company = null;

    #[Field]
    public ?string $notes = null;
}
