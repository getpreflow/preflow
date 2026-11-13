<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Fixtures\ModelSchema;

use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Attributes\Relation;
use Preflow\Data\Model;

/** A relation with a meta line: the candidate's name, their email under it. */
#[Entity(table: 'applicant', type: 'applicant')]
final class Applicant extends Model
{
    #[Id]
    public ?string $uuid = null;

    #[Relation(to: 'candidate', labelField: 'name', metaField: 'email')]
    public ?string $candidate = null;

    #[Relation(to: 'company')]
    public ?string $company = null;
}
