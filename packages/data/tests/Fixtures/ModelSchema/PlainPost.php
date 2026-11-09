<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Fixtures\ModelSchema;

use Preflow\Data\Attributes\Entity;
use Preflow\Data\Model;

#[Entity(table: 'posts')]
final class PlainPost extends Model
{
}
