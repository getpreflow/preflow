<?php

declare(strict_types=1);

namespace Preflow\Publishing\Tests\Fixtures;

use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Model;

/** A class-backed type that publishes ("publishing" in its schema), read through PublishedModels. */
#[Entity(table: 'job', storage: 'json', type: 'job')]
final class Job extends Model
{
    #[Id]
    public ?string $uuid = null;

    #[Field]
    public string $title = '';
}
