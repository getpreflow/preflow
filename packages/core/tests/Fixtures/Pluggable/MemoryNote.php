<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Fixtures\Pluggable;

use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Model;

#[Entity(table: 'note', storage: 'memory')]
final class MemoryNote extends Model
{
    #[Id]
    public string $uuid = '';

    #[Field]
    public ?string $title = null;
}
